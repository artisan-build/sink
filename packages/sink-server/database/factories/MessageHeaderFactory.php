<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Database\Factories;

use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageHeader;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageHeader>
 */
final class MessageHeaderFactory extends Factory
{
    protected $model = MessageHeader::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'name' => 'X-Sink-Test',
            'value' => fake()->sentence(),
        ];
    }
}
