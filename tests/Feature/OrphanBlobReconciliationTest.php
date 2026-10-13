<?php

declare(strict_types=1);

use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use ArtisanBuild\SinkServer\Models\MessageBlobCleanupIntent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake((string) config('sink-server.disk'));
    config(['sink-server.orphan_blobs.grace_hours' => 24]);
});

it('deletes an unreferenced blob older than the grace period', function (): void {
    putReconciliationBlob('raw/test-app/orphaned.eml', 25);

    $this->artisan('sink:reconcile-blobs')
        ->expectsOutput('Reclaimed 1 orphaned Sink blobs.')
        ->assertSuccessful();

    Storage::disk((string) config('sink-server.disk'))->assertMissing('raw/test-app/orphaned.eml');
});

it('keeps an unreferenced blob within the grace period', function (): void {
    putReconciliationBlob('raw/test-app/recent.eml', 23);

    $this->artisan('sink:reconcile-blobs')
        ->expectsOutput('Reclaimed 0 orphaned Sink blobs.')
        ->assertSuccessful();

    Storage::disk((string) config('sink-server.disk'))->assertExists('raw/test-app/recent.eml');
});

it('keeps an old blob referenced by a message', function (): void {
    $objectKey = 'raw/test-app/referenced.eml';
    createReconciliationMessage($objectKey);
    putReconciliationBlob($objectKey, 25);

    $this->artisan('sink:reconcile-blobs')->assertSuccessful();

    Storage::disk((string) config('sink-server.disk'))->assertExists($objectKey);
});

it('keeps an old blob referenced by an attachment', function (): void {
    $objectKey = 'attachments/test-app/referenced.txt';
    $message = createReconciliationMessage('raw/test-app/attachment-parent.eml');
    MessageAttachment::query()->create([
        'message_id' => $message->getKey(),
        'filename' => 'referenced.txt',
        'mime' => 'text/plain',
        'size_bytes' => 10,
        'object_key' => $objectKey,
    ]);
    putReconciliationBlob($objectKey, 25);

    $this->artisan('sink:reconcile-blobs')->assertSuccessful();

    Storage::disk((string) config('sink-server.disk'))->assertExists($objectKey);
});

it('keeps an old blob referenced by a cleanup intent', function (): void {
    $objectKey = 'raw/test-app/pending-cleanup.eml';
    MessageBlobCleanupIntent::query()->create(['object_key' => $objectKey]);
    putReconciliationBlob($objectKey, 25);

    $this->artisan('sink:reconcile-blobs')->assertSuccessful();

    Storage::disk((string) config('sink-server.disk'))->assertExists($objectKey);
});

it('does not inspect blobs outside Sink prefixes', function (): void {
    putReconciliationBlob('unrelated/do-not-delete.txt', 25);

    $this->artisan('sink:reconcile-blobs')->assertSuccessful();

    Storage::disk((string) config('sink-server.disk'))->assertExists('unrelated/do-not-delete.txt');
});

it('lists old orphans without deleting them during a dry run', function (): void {
    $objectKey = 'attachments/test-app/dry-run.txt';
    putReconciliationBlob($objectKey, 25);

    $this->artisan('sink:reconcile-blobs', ['--dry-run' => true])
        ->expectsOutput("Would delete orphaned blob: {$objectKey}")
        ->expectsOutput('Would reclaim 1 orphaned Sink blobs.')
        ->assertSuccessful();

    Storage::disk((string) config('sink-server.disk'))->assertExists($objectKey);
});

it('schedules reconciliation weekly without changing hourly maintenance', function (): void {
    $events = collect(app(Schedule::class)->events());
    $maintenance = $events->first(fn (Event $event): bool => is_string($event->command)
        && str_contains($event->command, 'sink:maintain'));
    $reconciliation = $events->first(fn (Event $event): bool => is_string($event->command)
        && str_contains($event->command, 'sink:reconcile-blobs'));

    expect($maintenance)->toBeInstanceOf(Event::class)
        ->and($maintenance->expression)->toBe('0 * * * *')
        ->and($reconciliation)->toBeInstanceOf(Event::class)
        ->and($reconciliation->expression)->toBe('0 0 * * 0');
});

function putReconciliationBlob(string $objectKey, int $ageHours): void
{
    /** @var FilesystemAdapter $disk */
    $disk = Storage::disk((string) config('sink-server.disk'));
    $disk->put($objectKey, 'blob');

    if (! touch($disk->path($objectKey), now()->subHours($ageHours)->getTimestamp())) {
        throw new RuntimeException("Unable to set the test timestamp for [{$objectKey}].");
    }
}

function createReconciliationMessage(string $rawObjectKey): Message
{
    return Message::query()->create([
        'idempotency_key' => (string) Str::ulid(),
        'app' => 'reconciliation-test',
        'received_at' => now(),
        'size_bytes' => 4,
        'attachment_count' => 0,
        'link_count' => 0,
        'truncation' => 'none',
        'raw_object_key' => $rawObjectKey,
    ]);
}
