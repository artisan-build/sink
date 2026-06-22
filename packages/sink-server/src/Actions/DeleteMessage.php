<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Actions;

use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Support\Facades\Storage;

final class DeleteMessage
{
    public function __invoke(Message|int $message): int
    {
        if (! $message instanceof Message) {
            $message = Message::query()->with('attachments')->find($message);
        } else {
            $message->loadMissing('attachments');
        }

        if (! $message instanceof Message) {
            return 0;
        }

        $disk = Storage::disk((string) config('sink-server.disk'));
        $disk->delete($message->raw_object_key);

        foreach ($message->attachments as $attachment) {
            $disk->delete($attachment->object_key);
        }

        $message->delete();

        return 1;
    }
}
