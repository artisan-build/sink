<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Database\Factories;

use ArtisanBuild\SinkContracts\Truncation;
use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Message>
 */
final class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $idempotencyKey = (string) Str::ulid();

        return [
            'idempotency_key' => $idempotencyKey,
            'app' => fake()->slug(2),
            'stream' => fake()->optional()->word(),
            'subject' => fake()->sentence(),
            'from_address' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'message_id' => '<'.$idempotencyKey.'@example.test>',
            'sent_at' => now(),
            'received_at' => now(),
            'size_bytes' => fake()->numberBetween(100, 10_000),
            'attachment_count' => 0,
            'link_count' => 0,
            'truncation' => Truncation::None->value,
            'raw_object_key' => 'raw/fallback/'.$idempotencyKey.'.eml',
            'parsed_at' => null,
        ];
    }
}
