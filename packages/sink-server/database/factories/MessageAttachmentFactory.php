<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Database\Factories;

use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageAttachment>
 */
final class MessageAttachmentFactory extends Factory
{
    protected $model = MessageAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'filename' => 'attachment.txt',
            'mime' => 'text/plain',
            'size_bytes' => 12,
            'object_key' => 'attachments/fallback/test/1-attachment.txt',
        ];
    }
}
