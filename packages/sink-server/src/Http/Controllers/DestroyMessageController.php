<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Http\Controllers;

use ArtisanBuild\BuiltForCloud\Audit\AppActionActor;
use ArtisanBuild\BuiltForCloud\Audit\AppActionReason;
use ArtisanBuild\BuiltForCloud\Audit\AppActionRecorder;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\SinkServer\Actions\DeleteMessage;
use ArtisanBuild\SinkServer\Audit\SinkAction;
use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final class DestroyMessageController
{
    public function __invoke(
        Message $message,
        DeleteMessage $deleteMessage,
        ActingPrincipalResolver $principals,
        AppActionRecorder $actions,
    ): RedirectResponse {
        $actor = AppActionActor::fromActingPrincipal($principals->resolve());

        DB::transaction(function () use ($message, $deleteMessage, $actions, $actor): void {
            if ($deleteMessage($message) === 0) {
                return;
            }

            $actions->record(
                action: SinkAction::MessageDeleted,
                actor: $actor,
                reason: AppActionReason::Requested,
                naturalKey: (string) $message->getKey(),
            );
        });

        return redirect()->route('sink.inbox')->with('status', 'Message deleted.');
    }
}
