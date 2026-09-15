<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Audit\AppActionActor;
use ArtisanBuild\BuiltForCloud\Audit\AppActionReason;
use ArtisanBuild\BuiltForCloud\Audit\AppActionRecorder;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\SinkServer\Actions\DeleteMessage;
use ArtisanBuild\SinkServer\Audit\SinkAction;
use ArtisanBuild\SinkServer\Mcp\Concerns\FiltersMessages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Delete messages matching an explicit metadata scope. Refuses unscoped purges.')]
final class PurgeTool extends Tool
{
    use FiltersMessages;

    protected string $name = 'purge';

    public function handle(Request $request): Response
    {
        $validated = $request->validate($this->filterRules());

        if (! $this->hasExplicitScope($validated)) {
            return Response::json(['error' => 'refusing unscoped purge', 'deleted' => 0]);
        }

        $credential = request()->attributes->get(Credential::class);

        abort_unless($credential instanceof Credential, 401);

        $deleteMessage = app(DeleteMessage::class);
        $actions = app(AppActionRecorder::class);
        $actor = AppActionActor::apiToken($credential);

        $deleted = DB::transaction(function () use ($validated, $deleteMessage, $actions, $actor): int {
            $deleted = 0;

            $this->filteredMessages($validated)
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

        return Response::json(['deleted' => $deleted]);
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->filterSchema($schema);
    }
}
