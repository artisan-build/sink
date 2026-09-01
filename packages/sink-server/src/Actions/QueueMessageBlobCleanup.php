<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Actions;

use ArtisanBuild\SinkServer\Models\MessageBlobCleanupIntent;
use Illuminate\Database\Connection;

final class QueueMessageBlobCleanup
{
    /**
     * @param  iterable<int, string>  $objectKeys
     */
    public function __invoke(iterable $objectKeys, Connection $connection): void
    {
        $intentIds = collect($objectKeys)
            ->filter()
            ->unique()
            ->values()
            ->map(function (string $objectKey): int {
                return (int) MessageBlobCleanupIntent::query()->firstOrCreate([
                    'object_key' => $objectKey,
                ])->getKey();
            })
            ->all();

        if ($intentIds === []) {
            return;
        }

        $connection->afterCommit(function () use ($intentIds): void {
            app(CleanupMessageBlobs::class)($intentIds);
        });
    }
}
