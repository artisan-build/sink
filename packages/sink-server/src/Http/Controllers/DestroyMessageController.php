<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Http\Controllers;

use ArtisanBuild\SinkServer\Actions\DeleteMessage;
use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Http\RedirectResponse;

final class DestroyMessageController
{
    public function __invoke(Message $message, DeleteMessage $deleteMessage): RedirectResponse
    {
        $deleteMessage($message);

        return redirect()->route('sink.inbox')->with('status', 'Message deleted.');
    }
}
