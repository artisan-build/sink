<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Concerns;

use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;

trait FiltersMessages
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Message>
     */
    private function filteredMessages(array $filters): Builder
    {
        /** @var Builder<Message> $query */
        $query = Message::query();

        if (filled($filters['app'] ?? null)) {
            $query->where('app', (string) $filters['app']);
        }

        if (filled($filters['subject'] ?? null)) {
            $query->where('subject', (string) $filters['subject']);
        }

        if (filled($filters['subject_contains'] ?? null)) {
            $query->where('subject', 'like', '%'.$this->escapeLike((string) $filters['subject_contains']).'%');
        }

        if (filled($filters['recipient'] ?? null)) {
            $query->whereHas('recipients', function (Builder $query) use ($filters): void {
                $query->where('address', (string) $filters['recipient']);
            });
        }

        if (filled($filters['since'] ?? null)) {
            $query->where('received_at', '>=', (string) $filters['since']);
        }

        if (filled($filters['until'] ?? null)) {
            $query->where('received_at', '<=', (string) $filters['until']);
        }

        if (filled($filters['stream'] ?? null)) {
            $query->where('stream', (string) $filters['stream']);
        }

        return $query;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function filterRules(): array
    {
        return [
            'app' => ['nullable', 'string'],
            'subject' => ['nullable', 'string'],
            'subject_contains' => ['nullable', 'string'],
            'recipient' => ['nullable', 'string'],
            'since' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
            'stream' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterSchema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->description('Only messages for this app.'),
            'subject' => $schema->string()->description('Only messages with this exact subject.'),
            'subject_contains' => $schema->string()->description('Only messages whose subject contains this text.'),
            'recipient' => $schema->string()->description('Only messages with this recipient address.'),
            'since' => $schema->string()->description('Only messages received at or after this date/time.'),
            'until' => $schema->string()->description('Only messages received at or before this date/time.'),
            'stream' => $schema->string()->description('Only messages in this stream.'),
        ];
    }

    private function hasExplicitScope(array $filters): bool
    {
        foreach (array_keys($this->filterRules()) as $key) {
            if (filled($filters[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
