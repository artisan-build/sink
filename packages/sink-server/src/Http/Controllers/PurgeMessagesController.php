<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Http\Controllers;

use ArtisanBuild\SinkServer\Actions\DeleteMessage;
use ArtisanBuild\SinkServer\Http\Livewire\InboxList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PurgeMessagesController
{
    public function __invoke(Request $request, DeleteMessage $deleteMessage): RedirectResponse
    {
        $filters = $request->only(['app', 'recipient', 'subject', 'receivedFrom', 'receivedTo']);

        abort_if(collect($filters)->every(fn (mixed $value): bool => blank($value)), 422, 'Refusing unscoped purge.');

        $deleted = 0;

        InboxList::filteredQuery($filters)
            ->pluck('id')
            ->each(function (int $messageId) use (&$deleted, $deleteMessage): void {
                $deleted += $deleteMessage($messageId);
            });

        return redirect()->route('sink.inbox')->with('status', "Purged {$deleted} messages.");
    }
}
