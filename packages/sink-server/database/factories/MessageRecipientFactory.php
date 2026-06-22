<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Database\Factories;

use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageRecipient>
 */
final class MessageRecipientFactory extends Factory
{
    protected $model = MessageRecipient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'kind' => 'to',
            'address' => fake()->safeEmail(),
            'name' => fake()->name(),
        ];
    }
}
