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
use ArtisanBuild\SinkServer\Models\MessageRecipient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List recipient addresses and kinds for messages matching optional filters.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class RecipientsTool extends Tool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;
    use FiltersMessages;
    use RespectsEffectCeiling;

    protected string $name = 'recipients';

    public function handle(Request $request): Response
    {
        $validated = $request->validate($this->filterRules());
        $messageIds = $this->filteredMessages($validated)->pluck('id');

        $recipients = MessageRecipient::query()
            ->whereIn('message_id', $messageIds)
            ->select(['address', 'kind'])
            ->distinct()
            ->orderBy('address')
            ->orderBy('kind')
            ->get()
            ->map(fn (MessageRecipient $recipient): array => [
                'address' => $recipient->address,
                'kind' => $recipient->kind,
            ])
            ->values()
            ->all();

        return Response::json($recipients);
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->filterSchema($schema);
    }
}
