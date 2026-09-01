<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Actions;

use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

final class DeleteMessage
{
    public function __invoke(Message|int $message): int
    {
        if (! $message instanceof Message) {
            $message = Message::query()->with('attachments')->find($message);
        }

        if (! $message instanceof Message) {
            return 0;
        }

        $connection = $message->getConnection();
        $defaultConnection = DB::connection();

        if ($connection !== $defaultConnection || $connection->getPdo() !== $defaultConnection->getPdo()) {
            throw new LogicException('Message deletion requires the Sink and application database to share one connection.');
        }

        $message->loadMissing('attachments');

        $disk = Storage::disk((string) config('sink-server.disk'));
        $rawObjectKey = $message->raw_object_key;
        $attachmentObjectKeys = $message->attachments->pluck('object_key')->all();
        $deleted = $message->newModelQuery()->whereKey($message->getKey())->delete();

        if ($deleted === 0) {
            return 0;
        }

        $connection->afterCommit(function () use ($disk, $rawObjectKey, $attachmentObjectKeys): void {
            $disk->delete($rawObjectKey);

            foreach ($attachmentObjectKeys as $attachmentObjectKey) {
                $disk->delete($attachmentObjectKey);
            }
        });

        return $deleted;
    }
}
