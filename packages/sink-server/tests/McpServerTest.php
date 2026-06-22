<?php

declare(strict_types=1);

use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use ArtisanBuild\SinkServer\Models\MessageHeader;
use ArtisanBuild\SinkServer\Models\MessageLink;
use ArtisanBuild\SinkServer\Models\MessageRecipient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Storage::fake((string) config('sink-server.disk'));
});

it('fails closed for unauthenticated MCP HTTP requests and initializes with a valid token', function (): void {
    $initialize = initializePayload();

    $this->postJson((string) config('sink-server.mcp.path'), $initialize)->assertUnauthorized();
    $this->postJson((string) config('sink-server.mcp.path'), $initialize, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

    $this->postJson((string) config('sink-server.mcp.path'), $initialize, ['Authorization' => 'Bearer test-token'])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Sink');
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

    expect(mcpTool('purge'))->toBe([
        'error' => 'refusing unscoped purge',
        'deleted' => 0,
    ]);
    $this->assertDatabaseCount('messages', 3, 'sink');

    expect(mcpTool('purge', ['app' => 'alpha']))->toBe(['deleted' => 2]);

    $this->assertDatabaseCount('messages', 1, 'sink');
    $this->assertDatabaseMissing('messages', ['id' => $message->id], 'sink');
    $this->assertDatabaseMissing('message_recipients', ['message_id' => $message->id], 'sink');
    Storage::disk((string) config('sink-server.disk'))->assertMissing($message->raw_object_key);
    Storage::disk((string) config('sink-server.disk'))->assertMissing('attachments/alpha/secret/guide.txt');
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
    ], ['Authorization' => 'Bearer test-token']);
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
