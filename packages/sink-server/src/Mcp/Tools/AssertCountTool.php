<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\SinkServer\Mcp\Concerns\FiltersMessages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Assert that the count of messages matching optional filters equals an expected integer.')]
#[IsReadOnly]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class AssertCountTool extends Tool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;
    use FiltersMessages;
    use RespectsEffectCeiling;

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
