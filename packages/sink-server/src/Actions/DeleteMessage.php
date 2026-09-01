<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Actions;

use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageBlobCleanupIntent;
use Illuminate\Support\Facades\DB;
use LogicException;

final class DeleteMessage
{
    public function __invoke(Message|int $message): int
    {
        $messageId = $message instanceof Message ? $message->getKey() : $message;
        $connection = (new Message)->getConnection();
        $defaultConnection = DB::connection();

        if ($connection !== $defaultConnection || $connection->getPdo() !== $defaultConnection->getPdo()) {
            throw new LogicException('Message deletion requires the Sink and application database to share one connection.');
        }

        return $connection->transaction(function () use ($messageId, $connection): int {
            $message = Message::query()->whereKey($messageId)->lockForUpdate()->first();

            if (! $message instanceof Message) {
                return 0;
            }

            $message->setRelation('attachments', $message->attachments()->lockForUpdate()->get());
            $objectKeys = $message->attachments
                ->pluck('object_key')
                ->prepend($message->raw_object_key)
                ->unique()
                ->values();
            $deleted = $message->newModelQuery()->whereKey($message->getKey())->delete();

            if ($deleted === 0) {
                return 0;
            }

            $intentIds = $objectKeys->map(function (string $objectKey): int {
                return (int) MessageBlobCleanupIntent::query()->firstOrCreate([
                    'object_key' => $objectKey,
                ])->getKey();
            })->all();

            $connection->afterCommit(function () use ($intentIds): void {
                app(CleanupMessageBlobs::class)($intentIds);
            });

            return $deleted;
        });
    }
}
