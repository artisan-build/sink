<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Tools;

use ArtisanBuild\SinkServer\Mcp\Concerns\FiltersMessages;
use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List recent Sink messages as metadata only. Never returns email body text.')]
#[IsReadOnly]
final class ListRecentTool extends Tool
{
    use FiltersMessages;

    protected string $name = 'list_recent';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            ...$this->filterRules(),
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);

        $messages = $this->filteredMessages($validated)
            ->with(['recipients', 'attachments'])
            ->latest('received_at')
            ->limit($limit)
            ->get()
            ->map(fn (Message $message): array => [
                'id' => $message->id,
                'app' => $message->app,
                'subject' => $message->subject,
                'from_address' => $message->from_address,
                'to' => $message->recipients->where('kind', 'to')->pluck('address')->values()->all(),
                'sent_at' => $message->sent_at?->toJSON(),
                'received_at' => $message->received_at?->toJSON(),
                'size_bytes' => (int) $message->size_bytes,
                'attachment_count' => (int) $message->attachment_count,
                'attachment_names' => $message->attachments->pluck('filename')->values()->all(),
                'link_count' => (int) $message->link_count,
                'truncation' => $message->truncation,
            ])
            ->values()
            ->all();

        return Response::json($messages);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->filterSchema($schema),
            'limit' => $schema->integer()->description('Maximum messages to return. Defaults to 20, max 200.'),
        ];
    }
}
