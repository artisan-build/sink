<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Tools;

use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List Sink apps with message counts and most recent receipt time.')]
#[IsReadOnly]
final class ListAppsTool extends Tool
{
    protected string $name = 'list_apps';

    public function handle(Request $request): Response
    {
        $apps = Message::query()
            ->selectRaw('app, count(*) as aggregate_count, max(received_at) as last_seen')
            ->groupBy('app')
            ->orderBy('app')
            ->get()
            ->map(fn (Message $message): array => [
                'app' => $message->app,
                'count' => (int) $message->aggregate_count,
                'last_seen' => $message->last_seen,
            ])
            ->values()
            ->all();

        return Response::json($apps);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
