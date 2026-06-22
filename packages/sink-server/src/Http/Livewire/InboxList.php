<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Http\Livewire;

use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Inbox')]
final class InboxList extends Component
{
    use WithPagination;

    public string $app = '';

    public string $recipient = '';

    public string $subject = '';

    public string $receivedFrom = '';

    public string $receivedTo = '';

    public function updating(string $property): void
    {
        if (in_array($property, ['app', 'recipient', 'subject', 'receivedFrom', 'receivedTo'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        return view('sink-server::livewire.inbox-list', [
            'messages' => self::filteredQuery($this->filters())
                ->with('recipients')
                ->withCount('recipients')
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->paginate(15),
        ]);
    }

    /**
     * @param  array{app?: mixed, recipient?: mixed, subject?: mixed, receivedFrom?: mixed, receivedTo?: mixed}  $filters
     * @return Builder<Message>
     */
    public static function filteredQuery(array $filters): Builder
    {
        return Message::query()
            ->when(filled($filters['app'] ?? null), fn (Builder $query): Builder => $query->where('app', (string) $filters['app']))
            ->when(filled($filters['recipient'] ?? null), function (Builder $query) use ($filters): Builder {
                return $query->whereHas('recipients', fn (Builder $recipientQuery): Builder => $recipientQuery->where('address', 'like', '%'.(string) $filters['recipient'].'%'));
            })
            ->when(filled($filters['subject'] ?? null), fn (Builder $query): Builder => $query->where('subject', 'like', '%'.(string) $filters['subject'].'%'))
            ->when(filled($filters['receivedFrom'] ?? null), fn (Builder $query): Builder => $query->whereDate('received_at', '>=', (string) $filters['receivedFrom']))
            ->when(filled($filters['receivedTo'] ?? null), fn (Builder $query): Builder => $query->whereDate('received_at', '<=', (string) $filters['receivedTo']));
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
