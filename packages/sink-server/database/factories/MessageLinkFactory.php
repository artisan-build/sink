<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Database\Factories;

use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageLink>
 */
final class MessageLinkFactory extends Factory
{
    protected $model = MessageLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'url' => fake()->url(),
            'label' => null,
        ];
    }
}
