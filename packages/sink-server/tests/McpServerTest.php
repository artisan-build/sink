<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Audit\AppActionEvent;
use ArtisanBuild\BuiltForCloud\Audit\AppActionOutboxEntry;
use ArtisanBuild\BuiltForCloud\Audit\AppActionReason;
use ArtisanBuild\BuiltForCloud\Console\ConsoleKeyring;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\Testing\McpProductAdmission;
use ArtisanBuild\SinkServer\Audit\SinkAction;
use ArtisanBuild\SinkServer\Mcp\Middleware\AuthenticateSinkMcp;
use ArtisanBuild\SinkServer\Mcp\SinkMcpServer;
use ArtisanBuild\SinkServer\Mcp\Tools\AssertCountTool;
use ArtisanBuild\SinkServer\Mcp\Tools\BodyMatchesTool;
use ArtisanBuild\SinkServer\Mcp\Tools\CountMessagesTool;
use ArtisanBuild\SinkServer\Mcp\Tools\LinksTool;
use ArtisanBuild\SinkServer\Mcp\Tools\ListAppsTool;
use ArtisanBuild\SinkServer\Mcp\Tools\ListRecentTool;
use ArtisanBuild\SinkServer\Mcp\Tools\MessageDetailTool;
use ArtisanBuild\SinkServer\Mcp\Tools\PurgeTool;
use ArtisanBuild\SinkServer\Mcp\Tools\RecipientsTool;
use ArtisanBuild\SinkServer\Mcp\Tools\StatsTool;
use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use ArtisanBuild\SinkServer\Models\MessageBlobCleanupIntent;
use ArtisanBuild\SinkServer\Models\MessageHeader;
use ArtisanBuild\SinkServer\Models\MessageLink;
use ArtisanBuild\SinkServer\Models\MessageRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Laravel\Mcp\Server\Middleware\ReorderJsonAccept;
use Laravel\Mcp\Server\Middleware\ValidateMcpHeaders;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\Version4\AsymmetricSecretKey;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\Purpose;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config([
        'built-for-cloud.console.issuer' => 'https://scalpels.test',
        'built-for-cloud.console.audience' => 'https://sink.test',
    ]);
    Storage::fake((string) config('sink-server.disk'));
    mcpCredential('mcp-token');
});

it('registers the exact read-scoped web MCP transport and declares truthful metadata', function (): void {
    $legacyPath = (string) config('sink-server.mcp.path');
    $readPath = (string) config('sink-server.mcp.read_path');
    $legacyRoute = Mcp::getWebServer(ltrim($legacyPath, '/'));
    $readRoute = Mcp::getWebServer(ltrim($readPath, '/'));

    expect($legacyRoute)->not->toBeNull()
        ->and($legacyRoute?->gatherMiddleware())->toBe([
            ReorderJsonAccept::class,
            ValidateMcpHeaders::class,
            AddWwwAuthenticateHeader::class,
            AuthenticateSinkMcp::class,
        ])
        ->and($readRoute)->not->toBeNull()
        ->and($readRoute?->gatherMiddleware())->toBe([
            ReorderJsonAccept::class,
            ValidateMcpHeaders::class,
            AddWwwAuthenticateHeader::class,
            'bfc.mcp:product,read',
        ])
        ->and(Mcp::getLocalServer('sink'))->toBeNull()
        ->and(config('sink-server.mcp'))->toBe([
            'path' => '/mcp',
            'read_path' => '/mcp/read',
        ])
        ->and(config('built-for-cloud.mcp'))->toBe([
            'path' => '/mcp/read',
            'write_path' => null,
            'delegated' => true,
        ]);

    $metadata = $this->getJson('/bfc/meta')->assertOk();

    expect($metadata->json('capabilities'))->toContain('mcp-serve', 'mcp-delegated', 'mcp-effect-scoped')
        ->and($metadata->json('endpoints'))->toBe(['mcp' => '/mcp/read']);
});

it('fails closed for unauthenticated MCP HTTP requests and initializes with a valid token', function (): void {
    $initialize = initializePayload();

    $this->postJson((string) config('sink-server.mcp.path'), $initialize)->assertUnauthorized();
    $this->postJson((string) config('sink-server.mcp.path'), $initialize, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

    $this->postJson((string) config('sink-server.mcp.path'), $initialize, ['Authorization' => 'Bearer mcp-token'])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Sink');
});

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

it('denies invalid direct MCP credential classes without recording usage', function (string $case): void {
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
            'wrong-purpose' => ['purpose' => CredentialPurpose::Consumption],
            'basic' => ['kind' => CredentialKind::Basic],
        };
        mcpCredential($secret, $attributes);
    }

    $this->postJson((string) config('sink-server.mcp.path'), initializePayload(), [
        'Authorization' => 'Bearer '.$secret,
    ])->assertUnauthorized();

    expect(Credential::query()->where('secret_hash', hash('sha256', $secret))->value('last_used_at'))->toBeNull();
})->with(['pending', 'wrong-purpose', 'basic', 'legacy']);

it('keeps the legacy route installation-only', function (string $case): void {
    $secret = 'denied-'.$case;
    $credential = mcpCredential($secret, match ($case) {
        'account-bound' => ['user_id' => '1'],
        'wrong-subject' => ['subject_type' => SubjectType::ExternalConsumer],
    });

    $this->postJson((string) config('sink-server.mcp.path'), initializePayload(), [
        'Authorization' => 'Bearer '.$secret,
    ])->assertUnauthorized();

    expect($credential->refresh()->last_used_at)->toBeNull();
})->with(['account-bound', 'wrong-subject']);

it('never falls through from an assertion-shaped bearer to a stored credential', function (): void {
    $assertionShapedBearer = 'v4.public.not-a-valid-assertion';
    $credential = mcpCredential($assertionShapedBearer);

    foreach (['sink-server.mcp.path', 'sink-server.mcp.read_path'] as $path) {
        $this->postJson((string) config($path), initializePayload(), [
            'Authorization' => 'Bearer '.$assertionShapedBearer,
        ])->assertUnauthorized();
    }

    expect($credential->refresh()->last_used_at)->toBeNull();
});

it('preserves all ten body-blind Sink tools for the direct installation bearer', function (): void {
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

it('conforms exactly the nine delegated read tools', function (): void {
    McpDelegatedTools::assertConforms(SinkMcpServer::class);

    $discovered = McpDelegatedTools::discover(SinkMcpServer::class);
    $expectedClasses = array_column(readToolDeclarations(), 'class');
    sort($expectedClasses);

    expect($discovered)->toBe([
        'tools' => $expectedClasses,
        'violations' => [],
    ]);
});

it('declares and advertises the exact classification and read effect for every delegated tool', function (): void {
    $signingKey = delegatedMcpSigningKey();
    fileDelegatedMcpKey($signingKey);
    $response = delegatedMcpRequest([
        'jsonrpc' => '2.0',
        'id' => 'declarations',
        'method' => 'tools/list',
    ], $signingKey)->assertOk();
    $wireTools = collect($response->json('result.tools'))->keyBy('name');

    foreach (readToolDeclarations() as $declaration) {
        $traits = class_uses_recursive($declaration['class']);
        $reflection = new ReflectionClass($declaration['class']);

        expect($reflection->getAttributes(IsReadOnly::class))->toHaveCount(1)
            ->and(ToolClassification::of($declaration['class'])?->value)->toBe($declaration['classification'])
            ->and(ToolEffect::of($declaration['class'])?->value)->toBe(Effect::Read)
            ->and($traits)->toContain(
                AdvertisesToolClassification::class,
                AdvertisesToolEffect::class,
                RespectsEffectCeiling::class,
            )
            ->and($wireTools->get($declaration['name'])['_meta'])->toBe([
                'classification' => $declaration['classification']->value,
                'effect' => Effect::Read->value,
            ]);
    }

    expect(ToolClassification::of(PurgeTool::class))->toBeNull()
        ->and(ToolEffect::of(PurgeTool::class))->toBeNull()
        ->and(class_uses_recursive(PurgeTool::class))->not->toContain(
            AdvertisesToolClassification::class,
            AdvertisesToolEffect::class,
            RespectsEffectCeiling::class,
        );
});

it('admits delegated assertions to read tools while excluding purge', function (): void {
    ['secret' => $message] = seedMcpMessages();
    $signingKey = delegatedMcpSigningKey();
    fileDelegatedMcpKey($signingKey);

    $list = delegatedMcpRequest([
        'jsonrpc' => '2.0',
        'id' => 'delegated-list',
        'method' => 'tools/list',
    ], $signingKey)->assertOk();
    $names = $list->json('result.tools.*.name');
    sort($names);

    expect($names)->toBe(array_column(readToolDeclarations(), 'name'));

    delegatedMcpRequest(toolCallPayload('count_messages', ['app' => 'alpha']), $signingKey)
        ->assertOk()
        ->assertJsonPath('result.content.0.text', json_encode(['count' => 2]));

    delegatedMcpRequest(toolCallPayload('purge', ['app' => 'alpha']), $signingKey)
        ->assertStatus(400)
        ->assertJsonPath('error.message', 'Tool [purge] not found.');

    delegatedMcpRequest(toolCallPayload('body_matches', [
        'id' => $message->id,
        'pattern' => 'TOPSECRETBODY',
    ]), $signingKey)->assertOk()->assertDontSee('TOPSECRETBODY');

    $this->assertDatabaseCount('messages', 3, 'sink');
    $this->assertDatabaseCount('bfc_app_action_events', 0, 'sink');
});

it('keeps purge absent and uncallable on the read door for a direct bearer', function (): void {
    seedMcpMessages();
    $readPath = (string) config('sink-server.mcp.read_path');
    $headers = ['Authorization' => 'Bearer mcp-token'];
    $list = $this->postJson($readPath, [
        'jsonrpc' => '2.0',
        'id' => 'direct-read-list',
        'method' => 'tools/list',
    ], $headers)->assertOk();

    expect($list->json('result.tools.*.name'))->not->toContain('purge');

    $this->postJson($readPath, toolCallPayload('purge', ['app' => 'alpha']), $headers)
        ->assertStatus(400)
        ->assertJsonPath('error.message', 'Tool [purge] not found.');

    $this->assertDatabaseCount('messages', 3, 'sink');
    $this->assertDatabaseCount('bfc_app_action_events', 0, 'sink');
});

it('passes the framework MCP product-admission conformance helper', function (): void {
    McpProductAdmission::assert();
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

/** @return array<string, mixed> */
function toolCallPayload(string $name, array $arguments = []): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $name.'-'.str()->random(6),
        'method' => 'tools/call',
        'params' => [
            'name' => $name,
            'arguments' => $arguments,
        ],
    ];
}

function delegatedMcpSigningKey(): AsymmetricSecretKey
{
    foreach (range(1, 16) as $ignored) {
        $secret = AsymmetricSecretKey::generate(new Version4);

        if (strlen($secret->raw()) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return $secret;
        }
    }

    throw new RuntimeException('Could not generate a signing key for the delegated MCP test.');
}

function fileDelegatedMcpKey(AsymmetricSecretKey $secret): void
{
    $keyring = new ConsoleKeyring;
    $keyring->add('sink-test-key', $secret->getPublicKey()->toHexString());
    $keyring->activate('sink-test-key');
}

function delegatedMcpAssertion(AsymmetricSecretKey $secret): string
{
    $now = CarbonImmutable::now();

    return (new Builder)
        ->setVersion(new Version4)
        ->setPurpose(Purpose::public())
        ->setKey($secret)
        ->setClaims([
            'iss' => 'https://scalpels.test',
            'sub' => 'sink-test-operator',
            'aud' => 'https://sink.test',
            'iat' => $now->toAtomString(),
            'nbf' => $now->toAtomString(),
            'exp' => $now->addSeconds(90)->toAtomString(),
            'jti' => 'sink_mint_'.bin2hex(random_bytes(8)),
            'display_name' => 'Sink Test Operator',
            'role' => 'member',
            'purpose' => 'mcp',
        ])
        ->setFooterArray(['kid' => 'sink-test-key'])
        ->toString();
}

/** @param array<string, mixed> $payload */
function delegatedMcpRequest(array $payload, AsymmetricSecretKey $secret): TestResponse
{
    return test()->postJson((string) config('sink-server.mcp.read_path'), $payload, [
        'Authorization' => 'Bearer '.delegatedMcpAssertion($secret),
    ]);
}

/**
 * @return list<array{class: class-string, name: string, classification: Classification}>
 */
function readToolDeclarations(): array
{
    $declarations = [
        ['class' => AssertCountTool::class, 'name' => 'assert_count', 'classification' => Classification::Metadata],
        ['class' => BodyMatchesTool::class, 'name' => 'body_matches', 'classification' => Classification::Metadata],
        ['class' => CountMessagesTool::class, 'name' => 'count_messages', 'classification' => Classification::Metadata],
        ['class' => LinksTool::class, 'name' => 'links', 'classification' => Classification::Content],
        ['class' => ListAppsTool::class, 'name' => 'list_apps', 'classification' => Classification::Content],
        ['class' => ListRecentTool::class, 'name' => 'list_recent', 'classification' => Classification::Content],
        ['class' => MessageDetailTool::class, 'name' => 'message_detail', 'classification' => Classification::Content],
        ['class' => RecipientsTool::class, 'name' => 'recipients', 'classification' => Classification::Content],
        ['class' => StatsTool::class, 'name' => 'stats', 'classification' => Classification::Content],
    ];

    usort($declarations, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

    return $declarations;
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
    return test()->postJson(
        (string) config('sink-server.mcp.path'),
        toolCallPayload($name, $arguments),
        ['Authorization' => 'Bearer mcp-token'],
    );
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
