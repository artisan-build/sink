<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Http\Livewire;

use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Message')]
final class MessageDetail extends Component
{
    public Message $message;

    public function mount(Message $message): void
    {
        $this->message = $message->load(['recipients', 'headers', 'links', 'attachments']);
    }

    public function render(): View
    {
        return view('sink-server::livewire.message-detail', [
            'recipientsByKind' => $this->message->recipients->groupBy('kind'),
        ]);
    }
}
