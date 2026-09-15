<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Audit\AppActionEvent;
use ArtisanBuild\BuiltForCloud\Audit\AppActionOutboxEntry;
use ArtisanBuild\BuiltForCloud\Audit\AppActionReason;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\SinkServer\Audit\SinkAction;
use ArtisanBuild\SinkServer\Mcp\Middleware\AuthenticateSinkMcp;
use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use ArtisanBuild\SinkServer\Models\MessageBlobCleanupIntent;
use ArtisanBuild\SinkServer\Models\MessageHeader;
use ArtisanBuild\SinkServer\Models\MessageLink;
use ArtisanBuild\SinkServer\Models\MessageRecipient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Storage::fake((string) config('sink-server.disk'));
    mcpCredential('mcp-token');
});

it('registers only the authenticated web MCP transport', function (): void {
    $path = (string) config('sink-server.mcp.path');
    $route = Mcp::getWebServer(ltrim($path, '/'));

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(AuthenticateSinkMcp::class)
        ->and(Mcp::getLocalServer('sink'))->toBeNull()
        ->and(config('sink-server.mcp'))->toBe(['path' => '/mcp']);
});

it('fails closed for unauthenticated MCP HTTP requests and initializes with a valid token', function (): void {
    $initialize = initializePayload();

    $this->postJson((string) config('sink-server.mcp.path'), $initialize)->assertUnauthorized();
    $this->postJson((string) config('sink-server.mcp.path'), $initialize, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

    $this->postJson((string) config('sink-server.mcp.path'), $initialize, ['Authorization' => 'Bearer mcp-token'])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Sink');
});

it('denies the fallback token for MCP requests including the purge tool', function (): void {
    seedMcpMessages();

    $this->postJson((string) config('sink-server.mcp.path'), initializePayload(), ['Authorization' => 'Bearer test-token'])
        ->assertUnauthorized();

    purgeToolResponseWithToken('test-token')->assertUnauthorized();
    $this->assertDatabaseCount('messages', 3, 'sink');
    $this->assertDatabaseCount('bfc_app_action_events', 0, 'sink');
    $this->assertDatabaseCount('bfc_app_action_outbox', 0, 'sink');
});

it('denies an expired token for MCP requests including the purge tool', function (): void {
    seedMcpMessages();
    mcpCredential('expired-token', ['expires_at' => now()->subMinute()]);

    $this->postJson((string) config('sink-server.mcp.path'), initializePayload(), ['Authorization' => 'Bearer expired-token'])
        ->assertUnauthorized();

    purgeToolResponseWithToken('expired-token')->assertUnauthorized();
    $this->assertDatabaseCount('messages', 3, 'sink');
    $this->assertDatabaseCount('bfc_app_action_events', 0, 'sink');
    $this->assertDatabaseCount('bfc_app_action_outbox', 0, 'sink');
});

it('denies a revoked token for MCP requests including the purge tool', function (): void {
    seedMcpMessages();
    mcpCredential('doomed-token', ['revoked_at' => now()]);

    $this->postJson((string) config('sink-server.mcp.path'), initializePayload(), ['Authorization' => 'Bearer doomed-token'])
        ->assertUnauthorized();

    purgeToolResponseWithToken('doomed-token')->assertUnauthorized();
    $this->assertDatabaseCount('messages', 3, 'sink');
    $this->assertDatabaseCount('bfc_app_action_events', 0, 'sink');
    $this->assertDatabaseCount('bfc_app_action_outbox', 0, 'sink');
});

it('denies every non-installation MCP credential class without recording usage', function (string $case): void {
    $secret = 'denied-'.$case;

    if ($case === 'legacy') {
        Credential::query()->insert([
            'id' => (string) str()->uuid(),
            'kind' => CredentialKind::Bearer->value,
            'purpose' => null,
            'subject_type' => SubjectType::Installation->value,
            'subject_ref' => 'legacy-mcp',
            'secret_hash' => hash('sha256', $secret),
            'status' => CredentialStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        $attributes = match ($case) {
            'pending' => ['status' => CredentialStatus::Pending],
            'account-bound' => ['user_id' => 'account-user'],
            'wrong-purpose' => ['purpose' => CredentialPurpose::Consumption],
            'wrong-subject' => ['subject_type' => SubjectType::ExternalConsumer],
            'basic' => ['kind' => CredentialKind::Basic],
        };
        mcpCredential($secret, $attributes);
    }

    $this->postJson((string) config('sink-server.mcp.path'), initializePayload(), [
        'Authorization' => 'Bearer '.$secret,
    ])->assertUnauthorized();

    expect(Credential::query()->where('secret_hash', hash('sha256', $secret))->value('last_used_at'))->toBeNull();
})->with(['pending', 'account-bound', 'wrong-purpose', 'wrong-subject', 'basic', 'legacy']);

it('scrubs the bearer before publishing the canonical credential downstream', function (): void {
    $request = Request::create('/mcp', 'POST', server: [
        'HTTP_AUTHORIZATION' => 'Bearer mcp-token',
        'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer mcp-token',
    ]);

    app(AuthenticateSinkMcp::class)->handle($request, function ($downstream): Response {
        expect($downstream->headers->get('Authorization'))->toBeNull()
            ->and($downstream->server->get('HTTP_AUTHORIZATION'))->toBeNull()
            ->and($downstream->server->get('REDIRECT_HTTP_AUTHORIZATION'))->toBeNull()
            ->and($downstream->attributes->get(Credential::class))->toBeInstanceOf(Credential::class);

        return response('ok');
    });
});

it('exposes exactly the ten body-blind Sink tools and no resources', function (): void {
    $tools = $this->postJson((string) config('sink-server.mcp.path'), [
        'jsonrpc' => '2.0',
        'id' => 'tools',
        'method' => 'tools/list',
    ], ['Authorization' => 'Bearer mcp-token'])->assertOk()->json('result.tools.*.name');
    sort($tools);

    expect($tools)->toBe([
        'assert_count',
        'body_matches',
        'count_messages',
        'links',
        'list_apps',
        'list_recent',
        'message_detail',
        'purge',
        'recipients',
        'stats',
    ]);

    $this->postJson((string) config('sink-server.mcp.path'), [
        'jsonrpc' => '2.0',
        'id' => 'resources',
        'method' => 'resources/list',
    ], ['Authorization' => 'Bearer mcp-token'])
        ->assertOk()
        ->assertJsonPath('result.resources', []);
});

it('counts messages and asserts expected counts across filters', function (): void {
    seedMcpMessages();

    expect(mcpTool('count_messages', ['app' => 'alpha'])['count'])->toBe(2)
        ->and(mcpTool('count_messages', ['subject_contains' => 'Reset'])['count'])->toBe(1)
        ->and(mcpTool('count_messages', ['recipient' => 'dev@example.test'])['count'])->toBe(2)
        ->and(mcpTool('count_messages', ['since' => '2026-06-21 00:00:00'])['count'])->toBe(2)
        ->and(mcpTool('count_messages', ['until' => '2026-06-20 23:59:59'])['count'])->toBe(1)
        ->and(mcpTool('count_messages', ['stream' => 'prod'])['count'])->toBe(2);

    expect(mcpTool('assert_count', ['app' => 'alpha', 'expected' => 2]))->toMatchArray([
        'expected' => 2,
        'actual' => 2,
        'pass' => true,
    ])->and(mcpTool('assert_count', ['app' => 'alpha', 'expected' => 3]))->toMatchArray([
        'expected' => 3,
        'actual' => 2,
        'pass' => false,
    ]);
});

it('lists recent metadata, recipients, and apps without bodies', function (): void {
    seedMcpMessages();

    $recentResponse = mcpToolResponse('list_recent', ['app' => 'alpha']);
    $recentResponse->assertOk()->assertDontSee('TOPSECRETBODY');
    $recent = mcpToolContent($recentResponse);

    expect($recent)->toHaveCount(2)
        ->and($recent[0])->toHaveKeys(['id', 'app', 'subject', 'from_address', 'to', 'sent_at', 'received_at', 'size_bytes', 'attachment_count', 'attachment_names', 'link_count', 'truncation'])
        ->and($recent[0]['app'])->toBe('alpha')
        ->and(mcpTool('recipients', ['app' => 'alpha']))->toContain([
            'address' => 'dev@example.test',
            'kind' => 'to',
        ]);

    expect(mcpTool('list_apps'))->toContain([
        'app' => 'alpha',
        'count' => 2,
        'last_seen' => '2026-06-22 10:00:00',
    ])->toContain([
        'app' => 'beta',
        'count' => 1,
        'last_seen' => '2026-06-21 09:00:00',
    ]);
});

it('returns stats by app, subject, and recipient domain', function (): void {
    seedMcpMessages();

    expect(mcpTool('stats', ['group_by' => 'app'])['rows'])->toContain([
        'key' => 'alpha',
        'count' => 2,
    ])->toContain([
        'key' => 'beta',
        'count' => 1,
    ]);

    expect(mcpTool('stats', ['group_by' => 'subject'])['rows'])->toContain([
        'key' => 'Reset your password',
        'count' => 1,
    ]);

    expect(mcpTool('stats', ['group_by' => 'recipient_domain'])['rows'])->toContain([
        'key' => 'example.test',
        'count' => 3,
    ])->toContain([
        'key' => 'other.test',
        'count' => 1,
    ]);
});

it('returns message detail and links while omitting raw and rendered body text', function (): void {
    ['secret' => $message] = seedMcpMessages();

    $detailResponse = mcpToolResponse('message_detail', ['id' => $message->id]);
    $linksResponse = mcpToolResponse('links', ['id' => $message->id]);
    $recentResponse = mcpToolResponse('list_recent', ['app' => 'alpha']);

    $detailResponse->assertOk()->assertDontSee('TOPSECRETBODY');
    $linksResponse->assertOk()->assertDontSee('TOPSECRETBODY');
    $recentResponse->assertOk()->assertDontSee('TOPSECRETBODY');

    expect(mcpToolContent($detailResponse))->toMatchArray([
        'id' => $message->id,
        'subject' => 'Reset your password',
        'message_id' => '<secret@example.test>',
        'attachment_count' => 1,
        'link_count' => 1,
        'headers' => [[
            'name' => 'X-Test',
            'value' => 'yes',
        ]],
        'attachments' => [[
            'filename' => 'guide.txt',
            'mime' => 'text/plain',
            'size_bytes' => 11,
        ]],
    ])->and(mcpToolContent($linksResponse))->toBe(['https://example.test/reset']);
});

it('matches body substrings without returning matched or body text', function (): void {
    ['secret' => $message] = seedMcpMessages();

    $matchingResponse = mcpToolResponse('body_matches', ['id' => $message->id, 'pattern' => 'Reset your password']);
    $matchingResponse->assertOk()->assertDontSee('TOPSECRETBODY')->assertDontSee('Reset your password');

    $matching = mcpToolContent($matchingResponse);
    expect($matching['matches'])->toBeTrue()
        ->and($matching['count'])->toBeGreaterThanOrEqual(1);

    expect(mcpTool('body_matches', ['id' => $message->id, 'pattern' => 'does-not-exist']))->toBe([
        'matches' => false,
        'count' => 0,
    ]);
});

it('purges scoped messages through the delete action and refuses unscoped purges', function (): void {
    ['secret' => $message] = seedMcpMessages();
    $credential = Credential::query()->where('name', 'mcp')->sole();

    expect(mcpTool('purge'))->toBe([
        'error' => 'refusing unscoped purge',
        'deleted' => 0,
    ]);
    $this->assertDatabaseCount('messages', 3, 'sink');
    $this->assertDatabaseCount('bfc_app_action_events', 0, 'sink');
    $this->assertDatabaseCount('bfc_app_action_outbox', 0, 'sink');

    expect(mcpTool('purge', ['app' => 'alpha']))->toBe(['deleted' => 2]);

    $this->assertDatabaseCount('messages', 1, 'sink');
    $this->assertDatabaseMissing('messages', ['id' => $message->id], 'sink');
    $this->assertDatabaseMissing('message_recipients', ['message_id' => $message->id], 'sink');
    Storage::disk((string) config('sink-server.disk'))->assertMissing($message->raw_object_key);
    Storage::disk((string) config('sink-server.disk'))->assertMissing('attachments/alpha/secret/guide.txt');

    $event = AppActionEvent::query()->sole();
    $ledger = AppActionOutboxEntry::query()->sole();

    expect($event->getAttributes())->toMatchArray([
        'action' => 'messages_purged',
        'action_vocabulary' => SinkAction::class,
        'reason' => AppActionReason::Requested->value,
        'actor_type' => 'api_token',
        'actor_ref' => (string) $credential->getKey(),
        'on_behalf_of' => null,
    ])->and($ledger->event_id)->toBe($event->id)
        ->and($credential->refresh()->last_used_at)->not->toBeNull();

    $rows = json_encode([$event->getAttributes(), $ledger->getAttributes()], JSON_THROW_ON_ERROR);

    expect($rows)->not->toContain('mcp-token')
        ->not->toContain('alpha')
        ->not->toContain('Reset your password')
        ->not->toContain('dev@example.test')
        ->not->toContain('TOPSECRETBODY');

    expect(mcpTool('purge', ['app' => 'alpha']))->toBe(['deleted' => 0]);
    $this->assertDatabaseCount('bfc_app_action_events', 1, 'sink');
    $this->assertDatabaseCount('bfc_app_action_outbox', 1, 'sink');
});

it('rolls the MCP database purge back when audit recording fails', function (): void {
    seedMcpMessages();

    DB::connection('sink')->unprepared(<<<'SQL'
        CREATE TRIGGER force_app_action_recorder_failure
        BEFORE INSERT ON bfc_app_action_events
        BEGIN
            SELECT RAISE(ABORT, 'forced app-action recorder failure');
        END
        SQL);

    try {
        $response = mcpToolResponse('purge', ['app' => 'alpha']);
        $response->assertOk()->assertJsonPath('result.isError', true);
    } finally {
        DB::connection('sink')->unprepared('DROP TRIGGER IF EXISTS force_app_action_recorder_failure');
    }

    $this->assertDatabaseCount('messages', 3, 'sink');
    $this->assertDatabaseCount('bfc_app_action_events', 0, 'sink');
    $this->assertDatabaseCount('bfc_app_action_outbox', 0, 'sink');
    expect(MessageBlobCleanupIntent::query()->count())->toBe(0);
    Storage::disk((string) config('sink-server.disk'))->assertExists('raw/alpha/secret.eml');
    Storage::disk((string) config('sink-server.disk'))->assertExists('attachments/alpha/secret/guide.txt');
});

it('keeps every read tool body blind', function (): void {
    ['secret' => $message] = seedMcpMessages();

    $calls = [
        ['list_apps', []],
        ['list_recent', ['app' => 'alpha']],
        ['count_messages', ['app' => 'alpha']],
        ['recipients', ['app' => 'alpha']],
        ['assert_count', ['app' => 'alpha', 'expected' => 2]],
        ['stats', ['group_by' => 'app']],
        ['message_detail', ['id' => $message->id]],
        ['links', ['id' => $message->id]],
        ['body_matches', ['id' => $message->id, 'pattern' => 'TOPSECRETBODY']],
    ];

    foreach ($calls as [$tool, $arguments]) {
        mcpToolResponse($tool, $arguments)->assertOk()->assertDontSee('TOPSECRETBODY');
    }
});

function initializePayload(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 'init-1',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'sink-tests',
                'version' => '1.0.0',
            ],
        ],
    ];
}

/** @param array<string, mixed> $attributes */
function mcpCredential(string $secret, array $attributes = []): Credential
{
    return Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'sink-installation',
        'name' => 'mcp',
        'status' => CredentialStatus::Active,
        'secret_hash' => hash('sha256', $secret),
        ...$attributes,
    ]);
}

function mcpTool(string $name, array $arguments = []): array
{
    return mcpToolContent(mcpToolResponse($name, $arguments));
}

function mcpToolResponse(string $name, array $arguments = []): TestResponse
{
    return test()->postJson((string) config('sink-server.mcp.path'), [
        'jsonrpc' => '2.0',
        'id' => $name.'-'.str()->random(6),
        'method' => 'tools/call',
        'params' => [
            'name' => $name,
            'arguments' => $arguments,
        ],
    ], ['Authorization' => 'Bearer mcp-token']);
}

function purgeToolResponseWithToken(string $plaintext): TestResponse
{
    return test()->postJson((string) config('sink-server.mcp.path'), [
        'jsonrpc' => '2.0',
        'id' => 'purge-denied',
        'method' => 'tools/call',
        'params' => [
            'name' => 'purge',
            'arguments' => ['app' => 'alpha'],
        ],
    ], ['Authorization' => 'Bearer '.$plaintext]);
}

function mcpToolContent(TestResponse $response): array
{
    $response->assertOk();

    $content = $response->json('result.content.0.text');

    expect($content)->toBeString();

    return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
}

function seedMcpMessages(): array
{
    $secret = seedMessage([
        'idempotency_key' => 'secret',
        'app' => 'alpha',
        'stream' => 'prod',
        'subject' => 'Reset your password',
        'from_address' => 'noreply@example.test',
        'from_name' => 'Example',
        'message_id' => '<secret@example.test>',
        'sent_at' => '2026-06-22 09:59:00',
        'received_at' => '2026-06-22 10:00:00',
        'size_bytes' => 512,
        'attachment_count' => 1,
        'link_count' => 1,
        'raw_object_key' => 'raw/alpha/secret.eml',
    ], 'Reset your password now. TOPSECRETBODY', [
        ['kind' => 'to', 'address' => 'dev@example.test'],
        ['kind' => 'cc', 'address' => 'ops@other.test'],
    ]);

    MessageHeader::factory()->create(['message_id' => $secret->id, 'name' => 'X-Test', 'value' => 'yes']);
    MessageLink::factory()->create(['message_id' => $secret->id, 'url' => 'https://example.test/reset']);
    MessageAttachment::factory()->create([
        'message_id' => $secret->id,
        'filename' => 'guide.txt',
        'mime' => 'text/plain',
        'size_bytes' => 11,
        'object_key' => 'attachments/alpha/secret/guide.txt',
    ]);
    Storage::disk((string) config('sink-server.disk'))->put('attachments/alpha/secret/guide.txt', 'hello world');

    $notice = seedMessage([
        'idempotency_key' => 'notice',
        'app' => 'alpha',
        'stream' => 'dev',
        'subject' => 'Build notice',
        'received_at' => '2026-06-20 08:00:00',
        'raw_object_key' => 'raw/alpha/notice.eml',
    ], 'A plain operational notice.', [
        ['kind' => 'to', 'address' => 'qa@example.test'],
    ]);

    $beta = seedMessage([
        'idempotency_key' => 'beta',
        'app' => 'beta',
        'stream' => 'prod',
        'subject' => 'Welcome',
        'received_at' => '2026-06-21 09:00:00',
        'raw_object_key' => 'raw/beta/welcome.eml',
    ], 'Welcome aboard.', [
        ['kind' => 'to', 'address' => 'dev@example.test'],
    ]);

    return compact('secret', 'notice', 'beta');
}

function seedMessage(array $attributes, string $body, array $recipients): Message
{
    /** @var Message $message */
    $message = Message::factory()->create([
        'sent_at' => $attributes['sent_at'] ?? $attributes['received_at'],
        'from_address' => $attributes['from_address'] ?? 'sender@example.test',
        'from_name' => $attributes['from_name'] ?? 'Sender',
        'message_id' => $attributes['message_id'] ?? '<'.$attributes['idempotency_key'].'@example.test>',
        ...$attributes,
    ]);

    foreach ($recipients as $recipient) {
        MessageRecipient::factory()->create(['message_id' => $message->id, ...$recipient]);
    }

    Storage::disk((string) config('sink-server.disk'))->put($message->raw_object_key, rawMime($message, $body));

    return $message;
}

function rawMime(Message $message, string $body): string
{
    return implode("\r\n", [
        'From: Sender <'.$message->from_address.'>',
        'To: Test <test@example.test>',
        'Subject: '.$message->subject,
        'Message-ID: '.$message->message_id,
        'Content-Type: text/plain; charset=UTF-8',
        '',
        $body,
    ]);
}
