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

        foreach ($intents as $intent) {
            if (Message::query()->where('raw_object_key', $intent->object_key)->exists()
                || MessageAttachment::query()->where('object_key', $intent->object_key)->exists()) {
                continue;
            }

            try {
                if (! $disk->delete($intent->object_key)) {
                    continue;
                }
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            $completed += MessageBlobCleanupIntent::query()->whereKey($intent->getKey())->delete();
        }

        return $completed;
    }
}
