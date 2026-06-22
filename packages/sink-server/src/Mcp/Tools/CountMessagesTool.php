<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Tools;

use ArtisanBuild\SinkServer\Mcp\Concerns\FiltersMessages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Count Sink messages matching optional metadata filters.')]
#[IsReadOnly]
final class CountMessagesTool extends Tool
{
    use FiltersMessages;

    protected string $name = 'count_messages';

    public function handle(Request $request): Response
    {
        $validated = $request->validate($this->filterRules());

        return Response::json(['count' => $this->filteredMessages($validated)->count()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->filterSchema($schema);
    }
}
