<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Http\Controllers;

use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MessageAttachmentController
{
    public function __invoke(Message $message, MessageAttachment $attachment): StreamedResponse
    {
        abort_unless((int) $attachment->message_id === (int) $message->getKey(), 404);

        return Storage::disk((string) config('sink-server.disk'))->download(
            $attachment->object_key,
            $this->safeFilename($attachment->filename)
        );
    }

    private function safeFilename(string $filename): string
    {
        $safe = Str::of(basename(str_replace('\\', '/', $filename)))
            ->replace(['/', '\\'], '-')
            ->replace('..', '')
            ->ltrim(". \t\n\r\0\x0B")
            ->trim()
            ->substr(0, 200)
            ->toString();

        return $safe === '' ? 'attachment' : $safe;
    }
}
