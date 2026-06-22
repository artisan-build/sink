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

#[Description('Return normalized URLs extracted from a message body without returning body text.')]
#[IsReadOnly]
final class LinksTool extends Tool
{
    protected string $name = 'links';

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['id' => ['required', 'integer']]);
        $message = Message::query()->with('links')->find((int) $validated['id']);

        if (! $message instanceof Message) {
            return Response::json(['error' => 'message not found']);
        }

        return Response::json($message->links->pluck('url')->values()->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->description('Message id.')];
    }
}
