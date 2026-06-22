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

#[Description('Assert that the count of messages matching optional filters equals an expected integer.')]
#[IsReadOnly]
final class AssertCountTool extends Tool
{
    use FiltersMessages;

    protected string $name = 'assert_count';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            ...$this->filterRules(),
            'expected' => ['required', 'integer'],
        ]);

        $actual = $this->filteredMessages($validated)->count();
        $expected = (int) $validated['expected'];

        return Response::json([
            'expected' => $expected,
            'actual' => $actual,
            'pass' => $actual === $expected,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->filterSchema($schema),
            'expected' => $schema->integer()->description('Required expected message count.'),
        ];
    }
}
