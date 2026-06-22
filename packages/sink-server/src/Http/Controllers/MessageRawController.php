<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Http\Controllers;

use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class MessageRawController
{
    public function __invoke(Message $message): Response
    {
        return response(Storage::disk((string) config('sink-server.disk'))->get($message->raw_object_key), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
