<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\SinkContracts\Envelope;
use ArtisanBuild\SinkContracts\Truncation;
use ArtisanBuild\SinkServer\Actions\DeleteMessage;
use ArtisanBuild\SinkServer\Jobs\ParseMessage;
use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use ArtisanBuild\SinkServer\Models\MessageBlobCleanupIntent;
use ArtisanBuild\SinkServer\Models\MessageHeader;
use ArtisanBuild\SinkServer\Models\MessageLink;
use ArtisanBuild\SinkServer\Models\MessageRecipient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake((string) config('sink-server.disk'));
    ingestCredential('ingest-token', 'test-app');
});

it('authenticates ingest requests only with a canonical installation consumption credential', function (): void {
    $payload = envelopePayload((string) Str::ulid(), simpleMime());

    $this->postJson('/ingest', $payload)->assertUnauthorized();
    $this->postJson('/ingest', $payload, ['Authorization' => 'Bearer wrong-token'])->assertUnauthorized();
    $this->postJson('/ingest', $payload, ['Authorization' => 'Bearer test-token'])->assertUnauthorized();

    $this->postJson('/ingest', $payload, ['Authorization' => 'Bearer ingest-token'])
        ->assertAccepted()
        ->assertJsonStructure(['id']);

    expect(Message::query()->sole()->app)->toBe('test-app')
        ->and(Credential::query()->where('subject_ref', 'test-app')->sole()->last_used_at)->not->toBeNull();
});

it('denies inadmissible ingest credentials without recording usage', function (string $case): void {
    $secret = 'denied-'.$case;
    $attributes = [];

    if ($case === 'legacy') {
        Credential::query()->insert([
            'id' => (string) Str::uuid(),
            'kind' => CredentialKind::Bearer->value,
            'purpose' => null,
            'subject_type' => SubjectType::Installation->value,
            'subject_ref' => 'legacy-app',
            'secret_hash' => hash('sha256', $secret),
            'status' => CredentialStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        $attributes = match ($case) {
            'pending' => ['status' => CredentialStatus::Pending],
            'revoked' => ['revoked_at' => now()],
            'expired' => ['expires_at' => now()->subMinute()],
            'account-bound' => ['user_id' => 'departed-user'],
            'wrong-purpose' => ['purpose' => CredentialPurpose::Mcp],
            'wrong-subject' => ['subject_type' => SubjectType::ExternalConsumer],
            'basic' => ['kind' => CredentialKind::Basic],
        };
        ingestCredential($secret, 'denied-app', $attributes);
    }

    $this->postJson('/ingest', envelopePayload((string) Str::ulid(), simpleMime()), [
        'Authorization' => 'Bearer '.$secret,
    ])->assertUnauthorized();

    expect(Message::query()->count())->toBe(0)
        ->and(Credential::query()->where('secret_hash', hash('sha256', $secret))->value('last_used_at'))->toBeNull();
})->with(['pending', 'revoked', 'expired', 'account-bound', 'wrong-purpose', 'wrong-subject', 'basic', 'legacy']);

it('rejects unsupported or malformed envelopes', function (): void {
    $newer = envelopePayload((string) Str::ulid(), simpleMime());
    $newer['envelope_version'] = Envelope::VERSION + 1;

    $this->postJson('/ingest', $newer, ['Authorization' => 'Bearer ingest-token'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Envelope v'.(Envelope::VERSION + 1).' is newer than this Sink instance (max v'.Envelope::VERSION.') — upgrade your Sink server.');

    $missingVersion = envelopePayload((string) Str::ulid(), simpleMime());
    unset($missingVersion['envelope_version']);

    $this->postJson('/ingest', $missingVersion, ['Authorization' => 'Bearer ingest-token'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Envelope is missing a numeric "envelope_version".');

    $missingIdempotency = envelopePayload((string) Str::ulid(), simpleMime());
    unset($missingIdempotency['idempotency_key']);

    $this->postJson('/ingest', $missingIdempotency, ['Authorization' => 'Bearer ingest-token'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Envelope is missing a non-empty string "idempotency_key".');
});

it('upserts messages by idempotency key', function (): void {
    $sameKey = (string) Str::ulid();

    $this->postJson('/ingest', envelopePayload($sameKey, simpleMime()), ['Authorization' => 'Bearer ingest-token'])
        ->assertAccepted();
    $message = Message::query()->where('idempotency_key', $sameKey)->firstOrFail();
    $firstObjectKey = $message->raw_object_key;

    $this->postJson('/ingest', envelopePayload($sameKey, simpleMime('Replay')), ['Authorization' => 'Bearer ingest-token'])
        ->assertAccepted();
    $message->refresh();

    expect(Message::query()->where('idempotency_key', $sameKey)->count())->toBe(1)
        ->and($message->raw_object_key)->not->toBe($firstObjectKey)
        ->and(MessageBlobCleanupIntent::query()->count())->toBe(0);
    Storage::disk((string) config('sink-server.disk'))->assertMissing($firstObjectKey);
    Storage::disk((string) config('sink-server.disk'))->assertExists($message->raw_object_key);

    $this->postJson('/ingest', envelopePayload((string) Str::ulid(), simpleMime()), ['Authorization' => 'Bearer ingest-token'])
        ->assertAccepted();
    $this->postJson('/ingest', envelopePayload((string) Str::ulid(), simpleMime()), ['Authorization' => 'Bearer ingest-token'])
        ->assertAccepted();

    expect(Message::query()->count())->toBe(3);
});

it('stores raw mime bytes in object storage', function (): void {
    $idempotencyKey = (string) Str::ulid();
    $raw = simpleMime('Stored raw bytes');

    $this->postJson('/ingest', envelopePayload($idempotencyKey, $raw), ['Authorization' => 'Bearer ingest-token'])
        ->assertAccepted();

    $message = Message::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();

    Storage::disk((string) config('sink-server.disk'))->assertExists($message->raw_object_key);

    expect(Storage::disk((string) config('sink-server.disk'))->get($message->raw_object_key))->toBe($raw)
        ->and($message->size_bytes)->toBe(strlen($raw));
});

it('parses metadata links and attachments without persisting body text', function (): void {
    $raw = multipartMime('..note.txt');

    $response = $this->postJson('/ingest', envelopePayload((string) Str::ulid(), $raw), ['Authorization' => 'Bearer ingest-token'])
        ->assertAccepted();

    (new ParseMessage((int) $response->json('id')))->handle();

    $message = Message::query()->with(['recipients', 'links', 'attachments'])->firstOrFail();

    expect($message->subject)->toBe('Sink Parse Test')
        ->and($message->from_address)->toBe('sender@example.com')
        ->and($message->recipients->pluck('address', 'kind')->all())->toMatchArray([
            'to' => 'to@example.com',
            'cc' => 'cc@example.com',
            'bcc' => 'bcc@example.com',
        ])
        ->and($message->links->pluck('url'))->toContain('https://example.com/x')
        ->and($message->attachment_count)->toBe(1)
        ->and($message->link_count)->toBeGreaterThanOrEqual(1)
        ->and($message->parsed_at)->not->toBeNull();

    $attachment = $message->attachments->first();

    expect($attachment)->not->toBeNull()
        ->and($attachment->filename)->toBe('note.txt')
        ->and($attachment->mime)->toBe('text/plain')
        ->and($attachment->size_bytes)->toBe(strlen('Attachment text.'));

    Storage::disk((string) config('sink-server.disk'))->assertExists($attachment->object_key);

    $persisted = [
        ...Message::query()->get()->flatMap(fn (Message $message): array => scalarValues($message->getAttributes()))->all(),
        ...MessageRecipient::query()->get()->flatMap(fn (MessageRecipient $recipient): array => scalarValues($recipient->getAttributes()))->all(),
        ...MessageHeader::query()->get()->flatMap(fn (MessageHeader $header): array => scalarValues($header->getAttributes()))->all(),
        ...MessageLink::query()->get()->flatMap(fn (MessageLink $link): array => scalarValues($link->getAttributes()))->all(),
        ...MessageAttachment::query()->get()->flatMap(fn (MessageAttachment $attachment): array => scalarValues($attachment->getAttributes()))->all(),
    ];

    expect(implode(' ', $persisted))->not->toContain('Secret body phrase');
});

it('captures parser-created attachments when deleting after a completed parse', function (): void {
    $response = $this->postJson(
        '/ingest',
        envelopePayload((string) Str::ulid(), multipartMime()),
        ['Authorization' => 'Bearer ingest-token'],
    )->assertAccepted();

    (new ParseMessage((int) $response->json('id')))->handle();

    $message = Message::query()->with('attachments')->findOrFail((int) $response->json('id'));
    $attachment = $message->attachments->sole();

    expect(app(DeleteMessage::class)($message))->toBe(1)
        ->and(MessageBlobCleanupIntent::query()->count())->toBe(0);

    Storage::disk((string) config('sink-server.disk'))->assertMissing($message->raw_object_key);
    Storage::disk((string) config('sink-server.disk'))->assertMissing($attachment->object_key);
});

it('reparses attachments onto immutable keys and cleans the replaced blobs', function (): void {
    $response = $this->postJson(
        '/ingest',
        envelopePayload((string) Str::ulid(), multipartMime()),
        ['Authorization' => 'Bearer ingest-token'],
    )->assertAccepted();
    $messageId = (int) $response->json('id');

    (new ParseMessage($messageId))->handle();
    $firstObjectKey = MessageAttachment::query()->where('message_id', $messageId)->sole()->object_key;

    (new ParseMessage($messageId))->handle();
    $secondObjectKey = MessageAttachment::query()->where('message_id', $messageId)->sole()->object_key;

    expect($secondObjectKey)->not->toBe($firstObjectKey)
        ->and(MessageBlobCleanupIntent::query()->count())->toBe(0);

    Storage::disk((string) config('sink-server.disk'))->assertMissing($firstObjectKey);
    Storage::disk((string) config('sink-server.disk'))->assertExists($secondObjectKey);
});

it('rejects non ulid idempotency keys before storage or database writes', function (string $idempotencyKey): void {
    $this->postJson('/ingest', envelopePayload($idempotencyKey, simpleMime()), ['Authorization' => 'Bearer ingest-token'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Envelope "idempotency_key" must be a valid ULID.');

    expect(Message::query()->count())->toBe(0)
        ->and(Storage::disk((string) config('sink-server.disk'))->allFiles())->toBe([]);
})->with([
    'slash' => '01ARZ3NDEKTSV4RRFFQ69G5FAV/x',
    'traversal' => '../../x',
]);

it('isolates idempotency by resolved app id', function (): void {
    ingestCredential('token-a', 'app-a');
    ingestCredential('token-b', 'app-b');

    $idempotencyKey = (string) Str::ulid();

    $this->postJson('/ingest', envelopePayload($idempotencyKey, simpleMime('App A')), ['Authorization' => 'Bearer token-a'])
        ->assertAccepted();
    $this->postJson('/ingest', envelopePayload($idempotencyKey, simpleMime('App B')), ['Authorization' => 'Bearer token-b'])
        ->assertAccepted();

    $messages = Message::query()->where('idempotency_key', $idempotencyKey)->orderBy('app')->get();
    $rawObjectKeys = $messages->pluck('raw_object_key')->all();

    expect($messages)->toHaveCount(2)
        ->and($messages->pluck('app')->all())->toBe(['app-a', 'app-b'])
        ->and($rawObjectKeys[0])->toStartWith('raw/app-a/')
        ->and($rawObjectKeys[1])->toStartWith('raw/app-b/')
        ->and($rawObjectKeys[0])->not->toBe($rawObjectKeys[1])
        ->and($rawObjectKeys[0])->toEndWith('.eml')
        ->and($rawObjectKeys[1])->toEndWith('.eml');
});

it('dispatches parse jobs on the configured queue after termination', function (): void {
    config(['sink-server.queue' => 'redis']);
    Bus::fake();

    $this->postJson('/ingest', envelopePayload((string) Str::ulid(), simpleMime()), ['Authorization' => 'Bearer ingest-token'])
        ->assertAccepted();

    Bus::assertDispatched(ParseMessage::class, fn (ParseMessage $job): bool => $job->connection === 'redis');
});

it('reports envelope capabilities without authentication', function (): void {
    $this->getJson('/capabilities')
        ->assertOk()
        ->assertJsonPath('envelope.min_major', 1)
        ->assertJsonPath('envelope.max_major', Envelope::VERSION);
});

it('prunes expired messages and enforces max message retention with blob cleanup', function (): void {
    config(['sink-server.retention.days' => 7]);

    $old = Message::factory()->create([
        'received_at' => now()->subDays(8),
        'raw_object_key' => 'raw/fallback/old.eml',
    ]);
    MessageRecipient::factory()->for($old)->create();
    MessageHeader::factory()->for($old)->create();
    MessageLink::factory()->for($old)->create();
    MessageAttachment::factory()->for($old)->create(['object_key' => 'attachments/fallback/old/1-note.txt']);

    $recent = Message::factory()->create([
        'received_at' => now(),
        'raw_object_key' => 'raw/fallback/recent.eml',
    ]);

    Storage::disk((string) config('sink-server.disk'))->put('raw/fallback/old.eml', 'old');
    Storage::disk((string) config('sink-server.disk'))->put('attachments/fallback/old/1-note.txt', 'attachment');
    Storage::disk((string) config('sink-server.disk'))->put('raw/fallback/recent.eml', 'recent');

    $this->artisan('sink:prune')->assertSuccessful();

    expect(Message::query()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(MessageRecipient::query()->where('message_id', $old->getKey())->exists())->toBeFalse()
        ->and(Message::query()->whereKey($recent->getKey())->exists())->toBeTrue();

    Storage::disk((string) config('sink-server.disk'))->assertMissing('raw/fallback/old.eml');
    Storage::disk((string) config('sink-server.disk'))->assertMissing('attachments/fallback/old/1-note.txt');
    Storage::disk((string) config('sink-server.disk'))->assertExists('raw/fallback/recent.eml');

    $recent->delete();

    config(['sink-server.retention.max_messages' => 1]);

    $olderCap = Message::factory()->create([
        'received_at' => now()->subMinutes(2),
        'raw_object_key' => 'raw/fallback/older-cap.eml',
    ]);
    $newerCap = Message::factory()->create([
        'received_at' => now()->subMinute(),
        'raw_object_key' => 'raw/fallback/newer-cap.eml',
    ]);

    Storage::disk((string) config('sink-server.disk'))->put($olderCap->raw_object_key, 'older');
    Storage::disk((string) config('sink-server.disk'))->put($newerCap->raw_object_key, 'newer');

    $this->artisan('sink:prune')->assertSuccessful();

    expect(Message::query()->count())->toBe(1)
        ->and(Message::query()->whereKey($newerCap->getKey())->exists())->toBeTrue();
});

function envelopePayload(string $idempotencyKey, string $raw): array
{
    return Envelope::make(
        idempotencyKey: $idempotencyKey,
        sentAt: now()->toIso8601String(),
        message: base64_encode($raw),
        stream: null,
        truncation: Truncation::None,
    )->toArray();
}

/** @param array<string, mixed> $attributes */
function ingestCredential(string $secret, string $app, array $attributes = []): Credential
{
    return Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => $app,
        'name' => $app,
        'status' => CredentialStatus::Active,
        'secret_hash' => hash('sha256', $secret),
        ...$attributes,
    ]);
}

function simpleMime(string $subject = 'Hello Sink'): string
{
    return "From: Sender <sender@example.com>\r\nTo: To <to@example.com>\r\nSubject: {$subject}\r\nMessage-ID: <".(string) Str::ulid().'@example.com>'."\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nPlain body.";
}

function multipartMime(string $filename = 'note.txt'): string
{
    $attachment = base64_encode('Attachment text.');

    return "From: Sender <sender@example.com>\r\nTo: To Person <to@example.com>\r\nCc: Cc Person <cc@example.com>\r\nBcc: Bcc Person <bcc@example.com>\r\nSubject: Sink Parse Test\r\nMessage-ID: <parse@example.com>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=outer\r\n\r\n--outer\r\nContent-Type: multipart/alternative; boundary=inner\r\n\r\n--inner\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nSecret body phrase plain.\r\n--inner\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<html><body>Secret body phrase <a href=\"https://example.com/x\">link</a></body></html>\r\n--inner--\r\n--outer\r\nContent-Type: text/plain; name=\"{$filename}\"\r\nContent-Disposition: attachment; filename=\"{$filename}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$attachment}\r\n--outer--\r\n";
}

function scalarValues(array $attributes): array
{
    return array_values(array_filter($attributes, static fn (mixed $value): bool => is_scalar($value)));
}
