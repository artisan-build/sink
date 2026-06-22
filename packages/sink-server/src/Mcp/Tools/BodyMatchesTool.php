<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp\Tools;

use ArtisanBuild\SinkServer\Models\Message as SinkMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ZBateson\MailMimeParser\Message as MimeMessage;

#[Description('Safely assert whether a message body contains a substring. Returns only a boolean and occurrence count, never body text.')]
#[IsReadOnly]
final class BodyMatchesTool extends Tool
{
    protected string $name = 'body_matches';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'pattern' => ['required', 'string'],
        ]);

        $message = SinkMessage::query()->find((int) $validated['id']);

        if (! $message instanceof SinkMessage) {
            return Response::json(['error' => 'message not found', 'matches' => false, 'count' => 0]);
        }

        $raw = Storage::disk((string) config('sink-server.disk'))->get($message->raw_object_key);
        $parsed = MimeMessage::from($raw, false);
        $body = ($parsed->getTextContent() ?? '')."\n".($parsed->getHtmlContent() ?? '');
        $pattern = (string) $validated['pattern'];
        $count = substr_count(Str::lower($body), Str::lower($pattern));

        return Response::json([
            'matches' => $count > 0,
            'count' => $count,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Message id.'),
            'pattern' => $schema->string()->description('Case-insensitive substring to search for.'),
        ];
    }
}
