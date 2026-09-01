<?php

declare(strict_types=1);

use ArtisanBuild\SinkServer\Actions\DeleteMessage;
use ArtisanBuild\SinkServer\Jobs\ParseMessage;
use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use ArtisanBuild\SinkServer\Models\MessageBlobCleanupIntent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            fwrite($parserChild, 'error:'.get_class($exception)."\n");
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
            fwrite($deleteChild, 'error:'.get_class($exception)."\n");
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

    DB::beginTransaction();
});

test('a delete-held message lock stops parsing before it can write an attachment blob', function (): void {
    requirePostgresConcurrencyHarness($this);

    $message = createConcurrencyMessage('delete-first');
    $messageId = (int) $message->getKey();
    $attachmentObjectKey = "attachments/{$message->app}/{$message->idempotency_key}/1-note.txt";
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
            fwrite($deleteChild, 'error:'.get_class($exception)."\n");
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
            fwrite($parserChild, 'error:'.get_class($exception)."\n");
        }

        fclose($parserChild);
        exit(0);
    }

    fclose($parserChild);

    expect(readConcurrencyLine($parserParent))->toBe('started');
    expect(concurrencyLineAvailable($parserParent, 250_000))->toBeFalse();
    Storage::disk((string) config('sink-server.disk'))->assertMissing($attachmentObjectKey);
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

    Storage::disk((string) config('sink-server.disk'))->assertMissing($attachmentObjectKey);

    DB::beginTransaction();
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

function reconnectConcurrencyDatabase(): void
{
    DB::purge();
}

function isLockedMessageQuery(QueryExecuted $query): bool
{
    return str_contains(strtolower($query->sql), 'from "messages"')
        && str_contains(strtolower($query->sql), 'for update');
}

function concurrencyMultipartMime(): string
{
    $attachment = base64_encode('Concurrent attachment.');

    return "From: Sender <sender@example.com>\r\nTo: To <to@example.com>\r\nSubject: Concurrent parse\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=outer\r\n\r\n--outer\r\nContent-Type: text/plain\r\n\r\nBody.\r\n--outer\r\nContent-Type: text/plain; name=\"note.txt\"\r\nContent-Disposition: attachment; filename=\"note.txt\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$attachment}\r\n--outer--\r\n";
}
