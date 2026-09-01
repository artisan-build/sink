<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Http\Controllers;

use ArtisanBuild\BuiltForCloud\Audit\AppActionActor;
use ArtisanBuild\BuiltForCloud\Audit\AppActionReason;
use ArtisanBuild\BuiltForCloud\Audit\AppActionRecorder;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\SinkServer\Actions\DeleteMessage;
use ArtisanBuild\SinkServer\Audit\SinkAction;
use ArtisanBuild\SinkServer\Http\Livewire\InboxList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PurgeMessagesController
{
    public function __invoke(
        Request $request,
        DeleteMessage $deleteMessage,
        ActingPrincipalResolver $principals,
        AppActionRecorder $actions,
    ): RedirectResponse {
        $filters = $request->only(['app', 'recipient', 'subject', 'receivedFrom', 'receivedTo']);

        abort_if(collect($filters)->every(fn (mixed $value): bool => blank($value)), 422, 'Refusing unscoped purge.');

        $actor = AppActionActor::fromActingPrincipal($principals->resolve());

        $deleted = DB::transaction(function () use ($filters, $deleteMessage, $actions, $actor): int {
            $deleted = 0;

            InboxList::filteredQuery($filters)
                ->pluck('id')
                ->each(function (int $messageId) use (&$deleted, $deleteMessage): void {
                    $deleted += $deleteMessage($messageId);
                });

            if ($deleted > 0) {
                $actions->record(
                    action: SinkAction::MessagesPurged,
                    actor: $actor,
                    reason: AppActionReason::Requested,
                );
            }

            return $deleted;
        });

        return redirect()->route('sink.inbox')->with('status', "Purged {$deleted} messages.");
    }
}
