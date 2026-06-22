<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Tools;

use ArtisanBuild\SinkServer\Mcp\Concerns\FiltersMessages;
use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageRecipient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Return grouped message statistics by app, subject, or recipient domain.')]
#[IsReadOnly]
final class StatsTool extends Tool
{
    use FiltersMessages;

    protected string $name = 'stats';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            ...$this->filterRules(),
            'group_by' => ['required', 'string', Rule::in(['app', 'subject', 'recipient_domain'])],
        ]);

        $groupBy = (string) $validated['group_by'];
        $rows = $groupBy === 'recipient_domain'
            ? $this->recipientDomainRows($validated)
            : $this->messageRows($validated, $groupBy);

        return Response::json([
            'group_by' => $groupBy,
            'rows' => $rows,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->filterSchema($schema),
            'group_by' => $schema->string()->description('One of app, subject, recipient_domain.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{key: string|null, count: int}>
     */
    private function messageRows(array $filters, string $column): array
    {
        return $this->filteredMessages($filters)
            ->selectRaw($column.', count(*) as aggregate_count')
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->map(fn (Message $message): array => [
                'key' => $message->{$column},
                'count' => (int) $message->aggregate_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{key: string, count: int}>
     */
    private function recipientDomainRows(array $filters): array
    {
        $messageIds = $this->filteredMessages($filters)->pluck('id');

        return MessageRecipient::query()
            ->whereIn('message_id', $messageIds)
            ->pluck('address')
            ->map(fn (string $address): ?string => str($address)->after('@')->lower()->toString() ?: null)
            ->filter()
            ->countBy()
            ->sortKeys()
            ->map(fn (int $count, string $domain): array => [
                'key' => $domain,
                'count' => $count,
            ])
            ->values()
            ->all();
    }
}
