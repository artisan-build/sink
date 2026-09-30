<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Audit\AppActionActor;
use ArtisanBuild\BuiltForCloud\Audit\AppActionReason;
use ArtisanBuild\BuiltForCloud\Audit\AppActionRecorder;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhase;
use ArtisanBuild\SinkServer\Actions\DeleteMessage;
use ArtisanBuild\SinkServer\Audit\SinkAction;
use ArtisanBuild\SinkServer\Mcp\Concerns\FiltersMessages;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Delete messages matching an explicit metadata scope. Refuses unscoped purges.')]
#[IsDestructive]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Destructive)]
#[TwoPhase]
final class PurgeTool extends Tool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;
    use FiltersMessages;
    use RespectsEffectCeiling;

    protected string $name = 'purge';

    public function preview(Request $request): Response
    {
        $validated = $request->validate($this->filterRules());

        if (! $this->hasExplicitScope($validated)) {
            return Response::error('refusing unscoped purge');
        }

        return Response::json([
            'scope' => $validated,
            'would_delete' => $this->filteredMessages($validated)->count(),
        ]);
    }

    public function handle(Request $request, ActingPrincipalResolver $principals): Response
    {
        $validated = $request->validate($this->filterRules());

        if (! $this->hasExplicitScope($validated)) {
            return Response::json(['error' => 'refusing unscoped purge', 'deleted' => 0]);
        }

        $deleteMessage = app(DeleteMessage::class);
        $actions = app(AppActionRecorder::class);
        $actor = AppActionActor::fromActingPrincipal($this->actingPrincipal($principals));

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

    private function actingPrincipal(ActingPrincipalResolver $principals): ActingPrincipal
    {
        $principal = $principals->resolve();

        if ($principal->check()) {
            return $principal;
        }

        $requestPrincipal = request()->user();
        abort_unless($requestPrincipal instanceof Authenticatable, 401);

        return ActingPrincipal::local('sink.mcp', $requestPrincipal);
    }
}
