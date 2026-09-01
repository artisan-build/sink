<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Actions;

use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use ArtisanBuild\SinkServer\Models\MessageBlobCleanupIntent;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CleanupMessageBlobs
{
    /**
     * @param  list<int>|null  $intentIds
     */
    public function __invoke(?array $intentIds = null): int
    {
        if ($intentIds === []) {
            return 0;
        }

        $intents = MessageBlobCleanupIntent::query()
            ->when($intentIds !== null, fn ($query) => $query->whereKey($intentIds))
            ->lazyById();
        $completed = 0;
        $disk = Storage::disk((string) config('sink-server.disk'));
        $connection = (new MessageBlobCleanupIntent)->getConnection();

        foreach ($intents as $candidate) {
            $intentId = $candidate->getKey();

            $completed += $connection->transaction(function () use ($intentId, $disk): int {
                $intent = MessageBlobCleanupIntent::query()->whereKey($intentId)->lockForUpdate()->first();

                if (! $intent instanceof MessageBlobCleanupIntent
                    || Message::query()->where('raw_object_key', $intent->object_key)->exists()
                    || MessageAttachment::query()->where('object_key', $intent->object_key)->exists()) {
                    return 0;
                }

                try {
                    if (! $disk->delete($intent->object_key)) {
                        return 0;
                    }
                } catch (Throwable $exception) {
                    report($exception);

                    return 0;
                }

                return MessageBlobCleanupIntent::query()->whereKey($intent->getKey())->delete();
            });
        }

        return $completed;
    }
}
