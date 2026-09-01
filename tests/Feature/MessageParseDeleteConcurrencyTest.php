<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\ApiToken;
use ArtisanBuild\BuiltForCloud\TokenRegistry;
use ArtisanBuild\SinkContracts\Envelope;
use ArtisanBuild\SinkContracts\Truncation;
use ArtisanBuild\SinkServer\Actions\CleanupMessageBlobs;
use ArtisanBuild\SinkServer\Actions\DeleteMessage;
use ArtisanBuild\SinkServer\Jobs\ParseMessage;
use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use ArtisanBuild\SinkServer\Models\MessageBlobCleanupIntent;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

afterEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        return;
    }

    if (Schema::hasTable((new Message)->getTable())) {
        Message::query()
            ->where('app', 'like', 'concurrency-harness-%')
            ->get()
            ->each(fn (Message $message): int => resolve(DeleteMessage::class)($message));
    }

    if (Schema::hasTable((new ApiToken)->getTable())) {
        ApiToken::query()->where('name', 'like', 'concurrency-harness-%')->delete();
    }

    if (DB::connection()->transactionLevel() === 0) {
        DB::beginTransaction();
    }
});

test('a parser-held message lock makes deletion wait and capture its attachment blob', function (): void {
    requirePostgresConcurrencyHarness($this);

    $message = createConcurrencyMessage('parser-first');
    $messageId = (int) $message->getKey();
    [$parserParent, $parserChild] = concurrencySocketPair();
    [$deleteParent, $deleteChild] = concurrencySocketPair();

    $parserPid = pcntl_fork();

    if ($parserPid === 0) {
        fclose($parserParent);
        fclose($deleteParent);
        fclose($deleteChild);
        reconnectConcurrencyDatabase();
        $paused = false;

        DB::listen(function (QueryExecuted $query) use (&$paused, $parserChild): void {
            if ($paused || ! isLockedMessageQuery($query)) {
                return;
            }

            $paused = true;
            fwrite($parserChild, "locked\n");
            fgets($parserChild);
        });

        try {
            (new ParseMessage($messageId))->handle();
            fwrite($parserChild, "parsed\n");
        } catch (Throwable $exception) {
            fwrite($parserChild, 'error:'.$exception::class."\n");
        }

        fclose($parserChild);
        exit(0);
    }

    fclose($parserChild);

    expect(readConcurrencyLine($parserParent))->toBe('locked');

    $deletePid = pcntl_fork();

    if ($deletePid === 0) {
        fclose($parserParent);
        fclose($deleteParent);
        reconnectConcurrencyDatabase();
        fwrite($deleteChild, "started\n");

        try {
            $deleted = resolve(DeleteMessage::class)($messageId);
            fwrite($deleteChild, "deleted:{$deleted}\n");
        } catch (Throwable $exception) {
            fwrite($deleteChild, 'error:'.$exception::class."\n");
        }

        fclose($deleteChild);
        exit(0);
    }

    fclose($deleteChild);

    expect(readConcurrencyLine($deleteParent))->toBe('started');
    expect(concurrencyLineAvailable($deleteParent, 250_000))->toBeFalse();
    fwrite($parserParent, "continue\n");

    expect(readConcurrencyLine($parserParent))->toBe('parsed')
        ->and(readConcurrencyLine($deleteParent))->toBe('deleted:1');

    pcntl_waitpid($parserPid, $parserStatus);
    pcntl_waitpid($deletePid, $deleteStatus);
    fclose($parserParent);
    fclose($deleteParent);
    reconnectConcurrencyDatabase();

    expect(pcntl_wexitstatus($parserStatus))->toBe(0)
        ->and(pcntl_wexitstatus($deleteStatus))->toBe(0)
        ->and(Message::query()->whereKey($message->getKey())->exists())->toBeFalse()
        ->and(MessageAttachment::query()->where('message_id', $message->getKey())->exists())->toBeFalse()
        ->and(MessageBlobCleanupIntent::query()->count())->toBe(0)
        ->and(Storage::disk((string) config('sink-server.disk'))->allFiles())->toBe([]);

});

test('a delete-held message lock stops parsing before it can write an attachment blob', function (): void {
    requirePostgresConcurrencyHarness($this);

    $message = createConcurrencyMessage('delete-first');
    $messageId = (int) $message->getKey();
    [$deleteParent, $deleteChild] = concurrencySocketPair();
    [$parserParent, $parserChild] = concurrencySocketPair();

    $deletePid = pcntl_fork();

    if ($deletePid === 0) {
        fclose($deleteParent);
        fclose($parserParent);
        fclose($parserChild);
        reconnectConcurrencyDatabase();
        $paused = false;

        DB::listen(function (QueryExecuted $query) use (&$paused, $deleteChild): void {
            if ($paused || ! isLockedMessageQuery($query)) {
                return;
            }

            $paused = true;
            fwrite($deleteChild, "locked\n");
            fgets($deleteChild);
        });

        try {
            $deleted = resolve(DeleteMessage::class)($messageId);
            fwrite($deleteChild, "deleted:{$deleted}\n");
        } catch (Throwable $exception) {
            fwrite($deleteChild, 'error:'.$exception::class."\n");
        }

        fclose($deleteChild);
        exit(0);
    }

    fclose($deleteChild);

    expect(readConcurrencyLine($deleteParent))->toBe('locked');

    $parserPid = pcntl_fork();

    if ($parserPid === 0) {
        fclose($deleteParent);
        fclose($parserParent);
        reconnectConcurrencyDatabase();
        fwrite($parserChild, "started\n");

        try {
            (new ParseMessage($messageId))->handle();
            fwrite($parserChild, "parsed\n");
        } catch (Throwable $exception) {
            fwrite($parserChild, 'error:'.$exception::class."\n");
        }

        fclose($parserChild);
        exit(0);
    }

    fclose($parserChild);

    expect(readConcurrencyLine($parserParent))->toBe('started');
    expect(concurrencyLineAvailable($parserParent, 250_000))->toBeFalse();
    assertNoConcurrencyAttachmentFiles();
    fwrite($deleteParent, "continue\n");

    expect(readConcurrencyLine($deleteParent))->toBe('deleted:1')
        ->and(readConcurrencyLine($parserParent))->toBe('error:Illuminate\\Database\\Eloquent\\ModelNotFoundException');

    pcntl_waitpid($deletePid, $deleteStatus);
    pcntl_waitpid($parserPid, $parserStatus);
    fclose($deleteParent);
    fclose($parserParent);
    reconnectConcurrencyDatabase();

    expect(pcntl_wexitstatus($deleteStatus))->toBe(0)
        ->and(pcntl_wexitstatus($parserStatus))->toBe(0)
        ->and(Message::query()->whereKey($message->getKey())->exists())->toBeFalse()
        ->and(MessageBlobCleanupIntent::query()->count())->toBe(0);

    assertNoConcurrencyAttachmentFiles();
    expect(Storage::disk((string) config('sink-server.disk'))->allFiles())->toBe([]);

});

test('the delete-first attachment instrument rejects an immutable-key orphan decoy', function (): void {
    Storage::fake((string) config('sink-server.disk'));
    $obsoleteDeterministicKey = 'attachments/app/idempotency/1-note.txt';
    $immutableDecoyKey = 'attachments/'.(string) Str::ulid().'/1-note.txt';
    Storage::disk((string) config('sink-server.disk'))->put($immutableDecoyKey, 'orphan');

    Storage::disk((string) config('sink-server.disk'))->assertMissing($obsoleteDeterministicKey);
    expect(fn () => assertNoConcurrencyAttachmentFiles())->toThrow(AssertionFailedError::class);

    Storage::disk((string) config('sink-server.disk'))->delete($immutableDecoyKey);
    assertNoConcurrencyAttachmentFiles();
});

test('two concurrent posts serialize immutable writes without orphaning the losing blob', function (bool $existingRow): void {
    requirePostgresConcurrencyHarness($this);

    $scenario = $existingRow ? 'repeat' : 'initial';
    $appId = "concurrency-harness-{$scenario}";
    $token = "concurrency-harness-token-{$scenario}";
    $idempotencyKey = (string) Str::ulid();
    $rawBodies = [simpleConcurrentRaw('First concurrent body'), simpleConcurrentRaw('Second concurrent body')];
    resolve(TokenRegistry::class)->store($appId, hash('sha256', $token));

    if ($existingRow) {
        $this->postJson('/ingest', concurrencyEnvelopePayload($idempotencyKey, simpleConcurrentRaw('Existing body')), [
            'Authorization' => "Bearer {$token}",
        ])->assertAccepted();
    }

    expect(Message::query()->where('app', $appId)->where('idempotency_key', $idempotencyKey)->count())
        ->toBe($existingRow ? 1 : 0);

    $pipes = [concurrencySocketPair(), concurrencySocketPair()];
    $pids = [];

    foreach ([0, 1] as $index) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            foreach ($pipes as $pipeIndex => [$parentStream, $childStream]) {
                fclose($parentStream);

                if ($pipeIndex !== $index) {
                    fclose($childStream);
                }
            }

            runConcurrentIngestProcess(
                stream: $pipes[$index][1],
                token: $token,
                payload: concurrencyEnvelopePayload($idempotencyKey, $rawBodies[$index]),
            );
        }

        $pids[$index] = $pid;
    }

    foreach ($pipes as [, $childStream]) {
        fclose($childStream);
    }

    foreach ($pipes as [$parentStream]) {
        expect(readConcurrencyLine($parentStream))->toBe('selected');
    }

    foreach ($pipes as [$parentStream]) {
        fwrite($parentStream, "continue\n");
    }

    [$firstIndex, $firstStored] = readConcurrencyLineFromAny([$pipes[0][0], $pipes[1][0]]);
    expect($firstStored)->toStartWith('stored:');
    $firstObjectKey = substr($firstStored, strlen('stored:'));
    fwrite($pipes[$firstIndex][0], "continue\n");
    $firstResponse = readConcurrencyLine($pipes[$firstIndex][0]);

    $secondIndex = $firstIndex === 0 ? 1 : 0;
    $secondStored = readConcurrencyLine($pipes[$secondIndex][0]);
    expect($secondStored)->toStartWith('stored:');
    $secondObjectKey = substr($secondStored, strlen('stored:'));

    expect($secondObjectKey)->not->toBe($firstObjectKey)
        ->and(Storage::disk((string) config('sink-server.disk'))->allFiles())->toContain(
            $firstObjectKey,
            $secondObjectKey,
        );

    fwrite($pipes[$secondIndex][0], "continue\n");
    $secondResponse = readConcurrencyLine($pipes[$secondIndex][0]);

    foreach ($pids as $index => $pid) {
        pcntl_waitpid($pid, $statuses[$index]);
        fclose($pipes[$index][0]);
    }

    reconnectConcurrencyDatabase();
    $message = Message::query()->where('app', $appId)->where('idempotency_key', $idempotencyKey)->sole();
    $responsePattern = '/^response:202:'.preg_quote((string) $message->getKey(), '/').'$/';

    expect($firstResponse)->toMatch($responsePattern)
        ->and($secondResponse)->toMatch($responsePattern)
        ->and(pcntl_wexitstatus($statuses[0]))->toBe(0)
        ->and(pcntl_wexitstatus($statuses[1]))->toBe(0)
        ->and(Message::query()->where('app', $appId)->where('idempotency_key', $idempotencyKey)->count())->toBe(1)
        ->and($message->raw_object_key)->toBe($secondObjectKey)
        ->and(Storage::disk((string) config('sink-server.disk'))->get($message->raw_object_key))->toBe($rawBodies[$secondIndex])
        ->and(MessageBlobCleanupIntent::query()->count())->toBe(0)
        ->and(Storage::disk((string) config('sink-server.disk'))->allFiles())->toBe([$message->raw_object_key]);

    Storage::disk((string) config('sink-server.disk'))->assertMissing($firstObjectKey);

    expect(resolve(DeleteMessage::class)($message))->toBe(1)
        ->and(ApiToken::query()->where('token_hash', hash('sha256', $token))->delete())->toBe(1)
        ->and(Storage::disk((string) config('sink-server.disk'))->allFiles())->toBe([]);
})->with([
    'absent-row initial ingest' => false,
    'existing-row repeat ingest' => true,
]);

test('cleanup cannot delete immutable bytes written by a concurrent reingest', function (): void {
    requirePostgresConcurrencyHarness($this);

    $appId = 'concurrency-harness-cleanup-race';
    $token = 'concurrency-harness-cleanup-race-token';
    $idempotencyKey = (string) Str::ulid();
    $oldObjectKey = "raw/{$appId}/{$idempotencyKey}.eml";
    $newRaw = concurrencyMultipartMime();
    resolve(TokenRegistry::class)->store($appId, hash('sha256', $token));
    Storage::disk((string) config('sink-server.disk'))->put($oldObjectKey, 'old raw');
    MessageBlobCleanupIntent::query()->create(['object_key' => $oldObjectKey]);
    [$cleanupParent, $cleanupChild] = concurrencySocketPair();

    $cleanupPid = pcntl_fork();

    if ($cleanupPid === 0) {
        fclose($cleanupParent);
        reconnectConcurrencyDatabase();
        $paused = false;

        DB::listen(function (QueryExecuted $query) use (&$paused, $cleanupChild): void {
            if ($paused || ! isCleanupReferenceQuery($query)) {
                return;
            }

            $paused = true;
            fwrite($cleanupChild, "checked\n");
            fgets($cleanupChild);
        });

        $cleaned = resolve(CleanupMessageBlobs::class)();
        fwrite($cleanupChild, "cleaned:{$cleaned}\n");
        fclose($cleanupChild);
        exit(0);
    }

    fclose($cleanupChild);
    expect(readConcurrencyLine($cleanupParent))->toBe('checked');

    $this->postJson('/ingest', concurrencyEnvelopePayload($idempotencyKey, $newRaw), [
        'Authorization' => "Bearer {$token}",
    ])->assertAccepted();

    $message = Message::query()->where('app', $appId)->where('idempotency_key', $idempotencyKey)->sole();
    fwrite($cleanupParent, "continue\n");

    expect(readConcurrencyLine($cleanupParent))->toBe('cleaned:1');
    pcntl_waitpid($cleanupPid, $cleanupStatus);
    fclose($cleanupParent);
    reconnectConcurrencyDatabase();
    $message->refresh();

    expect(pcntl_wexitstatus($cleanupStatus))->toBe(0)
        ->and($message->raw_object_key)->not->toBe($oldObjectKey)
        ->and(MessageBlobCleanupIntent::query()->count())->toBe(0)
        ->and(Storage::disk((string) config('sink-server.disk'))->get($message->raw_object_key))->toBe($newRaw);

    Storage::disk((string) config('sink-server.disk'))->assertMissing($oldObjectKey);

    expect(resolve(DeleteMessage::class)($message))->toBe(1)
        ->and(ApiToken::query()->where('token_hash', hash('sha256', $token))->delete())->toBe(1)
        ->and(Storage::disk((string) config('sink-server.disk'))->allFiles())->toBe([]);
});

test('concurrent cleanup workers serialize ownership of one intent', function (): void {
    requirePostgresConcurrencyHarness($this);

    $objectKey = 'raw/concurrent-cleanup.eml';
    Storage::disk((string) config('sink-server.disk'))->put($objectKey, 'raw');
    MessageBlobCleanupIntent::query()->create(['object_key' => $objectKey]);
    [$firstParent, $firstChild] = concurrencySocketPair();
    [$secondParent, $secondChild] = concurrencySocketPair();

    $firstPid = pcntl_fork();

    if ($firstPid === 0) {
        fclose($firstParent);
        fclose($secondParent);
        fclose($secondChild);
        reconnectConcurrencyDatabase();
        $paused = false;

        DB::listen(function (QueryExecuted $query) use (&$paused, $firstChild): void {
            if ($paused || ! isLockedCleanupIntentQuery($query)) {
                return;
            }

            $paused = true;
            fwrite($firstChild, "locked\n");
            fgets($firstChild);
        });

        $cleaned = resolve(CleanupMessageBlobs::class)();
        fwrite($firstChild, "cleaned:{$cleaned}\n");
        fclose($firstChild);
        exit(0);
    }

    fclose($firstChild);
    expect(readConcurrencyLine($firstParent))->toBe('locked');

    $secondPid = pcntl_fork();

    if ($secondPid === 0) {
        fclose($firstParent);
        fclose($secondParent);
        reconnectConcurrencyDatabase();
        fwrite($secondChild, "started\n");
        $cleaned = resolve(CleanupMessageBlobs::class)();
        fwrite($secondChild, "cleaned:{$cleaned}\n");
        fclose($secondChild);
        exit(0);
    }

    fclose($secondChild);
    expect(readConcurrencyLine($secondParent))->toBe('started')
        ->and(concurrencyLineAvailable($secondParent, 250_000))->toBeFalse();
    fwrite($firstParent, "continue\n");

    expect(readConcurrencyLine($firstParent))->toBe('cleaned:1')
        ->and(readConcurrencyLine($secondParent))->toBe('cleaned:0');

    pcntl_waitpid($firstPid, $firstStatus);
    pcntl_waitpid($secondPid, $secondStatus);
    fclose($firstParent);
    fclose($secondParent);
    reconnectConcurrencyDatabase();

    expect(pcntl_wexitstatus($firstStatus))->toBe(0)
        ->and(pcntl_wexitstatus($secondStatus))->toBe(0)
        ->and(MessageBlobCleanupIntent::query()->count())->toBe(0);
    Storage::disk((string) config('sink-server.disk'))->assertMissing($objectKey);

});

function requirePostgresConcurrencyHarness(object $test): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $test->markTestSkipped('PostgreSQL row-lock semantics are not claimed on SQLite.');
    }

    if (! function_exists('pcntl_fork')) {
        $test->markTestSkipped('The pcntl extension is required for the PostgreSQL concurrency control.');
    }

    DB::rollBack();
    Storage::fake((string) config('sink-server.disk'));
}

function createConcurrencyMessage(string $suffix): Message
{
    $idempotencyKey = (string) Str::ulid();
    $rawObjectKey = "raw/concurrency-{$suffix}.eml";
    $message = Message::query()->create([
        'idempotency_key' => $idempotencyKey,
        'app' => 'concurrency',
        'received_at' => now(),
        'size_bytes' => 1,
        'attachment_count' => 0,
        'link_count' => 0,
        'truncation' => 'none',
        'raw_object_key' => $rawObjectKey,
    ]);

    Storage::disk((string) config('sink-server.disk'))->put($rawObjectKey, concurrencyMultipartMime());

    return $message;
}

/**
 * @return array{0: resource, 1: resource}
 */
function concurrencySocketPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($pair === false) {
        throw new RuntimeException('Unable to create concurrency control socket pair.');
    }

    return $pair;
}

/** @param resource $stream */
function readConcurrencyLine($stream): string
{
    if (! concurrencyLineAvailable($stream, 5_000_000)) {
        throw new RuntimeException('Timed out waiting for a concurrency control process.');
    }

    return trim((string) fgets($stream));
}

/** @param resource $stream */
function concurrencyLineAvailable($stream, int $microseconds): bool
{
    $read = [$stream];
    $write = null;
    $except = null;
    $seconds = intdiv($microseconds, 1_000_000);
    $remainingMicroseconds = $microseconds % 1_000_000;

    return stream_select($read, $write, $except, $seconds, $remainingMicroseconds) > 0;
}

/**
 * @param  list<resource>  $streams
 * @return array{0: int, 1: string}
 */
function readConcurrencyLineFromAny(array $streams): array
{
    $read = $streams;
    $write = null;
    $except = null;

    if (stream_select($read, $write, $except, 5) < 1) {
        throw new RuntimeException('Timed out waiting for either concurrent process.');
    }

    $stream = array_values($read)[0];
    $index = array_search($stream, $streams, true);

    if (! is_int($index)) {
        throw new RuntimeException('Unable to identify the ready concurrent process.');
    }

    return [$index, trim((string) fgets($stream))];
}

/**
 * @param  resource  $stream
 * @param  array<string, mixed>  $payload
 */
function runConcurrentIngestProcess($stream, string $token, array $payload): never
{
    reconnectConcurrencyDatabase();
    $selected = false;

    DB::listen(function (QueryExecuted $query) use (&$selected, $stream): void {
        if ($selected || ! isUnlockedMessageQuery($query)) {
            return;
        }

        $selected = true;
        fwrite($stream, "selected\n");
        fgets($stream);
    });

    $disk = Storage::disk((string) config('sink-server.disk'));
    $blockingDisk = Mockery::mock(FilesystemAdapter::class);
    $blockingDisk->shouldReceive('put')->once()->andReturnUsing(function (string $path, string $contents) use ($disk, $stream): bool {
        $stored = $disk->put($path, $contents);
        fwrite($stream, "stored:{$path}\n");
        fgets($stream);

        return $stored;
    });
    $blockingDisk->shouldReceive('delete')->zeroOrMoreTimes()->andReturnUsing(
        fn (string $path): bool => $disk->delete($path),
    );
    Storage::shouldReceive('disk')->andReturn($blockingDisk);

    try {
        $request = Request::create(
            uri: '/ingest',
            method: 'POST',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => "Bearer {$token}",
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $response = resolve(HttpKernel::class)->handle($request);
        $responseBody = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        fwrite($stream, "response:{$response->getStatusCode()}:{$responseBody['id']}\n");
    } catch (Throwable $exception) {
        @fwrite($stream, 'error:'.$exception::class."\n");
    }

    fclose($stream);
    exit(0);
}

function isUnlockedMessageQuery(QueryExecuted $query): bool
{
    $sql = strtolower($query->sql);

    return str_starts_with(ltrim($sql), 'select')
        && str_contains($sql, 'from "messages"')
        && ! str_contains($sql, 'for update');
}

function reconnectConcurrencyDatabase(): void
{
    DB::purge();
}

function isLockedMessageQuery(QueryExecuted $query): bool
{
    return str_contains(strtolower($query->sql), 'from "messages"')
        && str_contains(strtolower($query->sql), 'for update');
}

function isCleanupReferenceQuery(QueryExecuted $query): bool
{
    return str_contains(strtolower($query->sql), 'from "message_attachments"');
}

function isLockedCleanupIntentQuery(QueryExecuted $query): bool
{
    return str_contains(strtolower($query->sql), 'from "message_blob_cleanup_intents"')
        && str_contains(strtolower($query->sql), 'for update');
}

/**
 * @return array<string, mixed>
 */
function concurrencyEnvelopePayload(string $idempotencyKey, string $raw): array
{
    return Envelope::make(
        idempotencyKey: $idempotencyKey,
        sentAt: now()->toIso8601String(),
        message: base64_encode($raw),
        stream: null,
        truncation: Truncation::None,
    )->toArray();
}

function concurrencyMultipartMime(): string
{
    $attachment = base64_encode('Concurrent attachment.');

    return "From: Sender <sender@example.com>\r\nTo: To <to@example.com>\r\nSubject: Concurrent parse\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=outer\r\n\r\n--outer\r\nContent-Type: text/plain\r\n\r\nBody.\r\n--outer\r\nContent-Type: text/plain; name=\"note.txt\"\r\nContent-Disposition: attachment; filename=\"note.txt\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$attachment}\r\n--outer--\r\n";
}

function simpleConcurrentRaw(string $subject): string
{
    return "From: Sender <sender@example.com>\r\nTo: To <to@example.com>\r\nSubject: {$subject}\r\nContent-Type: text/plain\r\n\r\nBody.";
}

function assertNoConcurrencyAttachmentFiles(): void
{
    Assert::assertSame([], Storage::disk((string) config('sink-server.disk'))->allFiles('attachments'));
}
