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

#[Description('Return full message metadata, headers, recipients, attachments, and counts. Never returns body text.')]
#[IsReadOnly]
final class MessageDetailTool extends Tool
{
    protected string $name = 'message_detail';

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['id' => ['required', 'integer']]);

        $message = Message::query()
            ->with(['recipients', 'headers', 'attachments'])
            ->find((int) $validated['id']);

        if (! $message instanceof Message) {
            return Response::json(['error' => 'message not found']);
        }

        return Response::json([
            'id' => $message->id,
            'app' => $message->app,
            'subject' => $message->subject,
            'from' => [
                'address' => $message->from_address,
                'name' => $message->from_name,
            ],
            'recipients' => [
                'to' => $message->recipients->where('kind', 'to')->pluck('address')->values()->all(),
                'cc' => $message->recipients->where('kind', 'cc')->pluck('address')->values()->all(),
                'bcc' => $message->recipients->where('kind', 'bcc')->pluck('address')->values()->all(),
            ],
            'message_id' => $message->message_id,
            'sent_at' => $message->sent_at?->toJSON(),
            'received_at' => $message->received_at?->toJSON(),
            'size_bytes' => (int) $message->size_bytes,
            'attachment_count' => (int) $message->attachment_count,
            'link_count' => (int) $message->link_count,
            'truncation' => $message->truncation,
            'headers' => $message->headers->map(fn ($header): array => [
                'name' => $header->name,
                'value' => $header->value,
            ])->values()->all(),
            'attachments' => $message->attachments->map(fn ($attachment): array => [
                'filename' => $attachment->filename,
                'mime' => $attachment->mime,
                'size_bytes' => (int) $attachment->size_bytes,
            ])->values()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->description('Message id.')];
    }
}
