<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Tools;

use ArtisanBuild\SinkServer\Actions\DeleteMessage;
use ArtisanBuild\SinkServer\Mcp\Concerns\FiltersMessages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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

        $deleted = 0;
        $deleteMessage = app(DeleteMessage::class);

        $this->filteredMessages($validated)
            ->pluck('id')
            ->each(function (int $messageId) use (&$deleted, $deleteMessage): void {
                $deleted += $deleteMessage($messageId);
            });

        return Response::json(['deleted' => $deleted]);
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->filterSchema($schema);
    }
}
