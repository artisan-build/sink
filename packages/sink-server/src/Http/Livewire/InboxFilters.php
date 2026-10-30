<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Http\Livewire;

use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The inbox's filters and purge, drawn in the sidebar beside the message list.
 *
 * The list is a separate component, so every change is announced with the
 * `inbox-filters-changed` event, and both read the same query string so a
 * reload or a shared link keeps the filters.
 */
final class InboxFilters extends Component
{
    #[Url(except: '')]
    public string $app = '';

    #[Url(except: '')]
    public string $recipient = '';

    #[Url(except: '')]
    public string $subject = '';

    #[Url(except: '')]
    public string $receivedFrom = '';

    #[Url(except: '')]
    public string $receivedTo = '';

    /**
     * Tell the message list the filters changed.
     */
    public function updated(): void
    {
        $this->dispatch('inbox-filters-changed', filters: $this->filters());
    }

    /**
     * Empty every filter and tell the message list.
     */
    public function clearFilters(): void
    {
        $this->reset('app', 'recipient', 'subject', 'receivedFrom', 'receivedTo');

        $this->updated();
    }

    public function render(): View
    {
        return view('sink-server::livewire.inbox-filters', [
            'filtered' => array_filter($this->filters(), filled(...)) !== [],
            'apps' => Message::query()->distinct()->orderBy('app')->pluck('app')->all(),
        ]);
    }

    /**
     * @return array{app: string, recipient: string, subject: string, receivedFrom: string, receivedTo: string}
     */
    private function filters(): array
    {
        return [
            'app' => $this->app,
            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'receivedFrom' => $this->receivedFrom,
            'receivedTo' => $this->receivedTo,
        ];
    }
}
