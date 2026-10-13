<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Actions;

use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use ArtisanBuild\SinkServer\Models\MessageBlobCleanupIntent;
use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ReconcileMessageBlobs
{
    /**
     * @var list<string>
     */
    private const array PREFIXES = ['raw', 'attachments'];

    private const int BATCH_SIZE = 500;

    public function __invoke(bool $dryRun = false, ?Closure $orphaned = null): int
    {
        $disk = Storage::disk((string) config('sink-server.disk'));
        $graceHours = max(1, (int) config('sink-server.orphan_blobs.grace_hours', 24));
        $cutoff = now()->subHours($graceHours)->getTimestamp();
        $candidates = [];
        $reconciled = 0;

        foreach (self::PREFIXES as $prefix) {
            foreach ($disk->getDriver()->listContents($prefix, true) as $attributes) {
                $lastModified = $attributes->lastModified();

                if (! $attributes->isFile()
                    || ! str_starts_with($attributes->path(), $prefix.'/')
                    || $lastModified === null
                    || $lastModified >= $cutoff) {
                    continue;
                }

                $candidates[] = $attributes->path();

                if (count($candidates) === self::BATCH_SIZE) {
                    $reconciled += $this->reconcileBatch($candidates, $disk, $dryRun, $orphaned);
                    $candidates = [];
                }
            }
        }

        if ($candidates !== []) {
            $reconciled += $this->reconcileBatch($candidates, $disk, $dryRun, $orphaned);
        }

        return $reconciled;
    }

    /**
     * @param  list<string>  $objectKeys
     */
    private function reconcileBatch(
        array $objectKeys,
        FilesystemAdapter $disk,
        bool $dryRun,
        ?Closure $orphaned,
    ): int {
        $referencedObjectKeys = array_fill_keys([
            ...Message::query()->whereIn('raw_object_key', $objectKeys)->pluck('raw_object_key')->all(),
            ...MessageAttachment::query()->whereIn('object_key', $objectKeys)->pluck('object_key')->all(),
            ...MessageBlobCleanupIntent::query()->whereIn('object_key', $objectKeys)->pluck('object_key')->all(),
        ], true);
        $reconciled = 0;

        foreach ($objectKeys as $objectKey) {
            if (isset($referencedObjectKeys[$objectKey])) {
                continue;
            }

            $orphaned?->__invoke($objectKey);

            if ($dryRun) {
                $reconciled++;

                continue;
            }

            try {
                if ($disk->delete($objectKey)) {
                    $reconciled++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $reconciled;
    }
}
