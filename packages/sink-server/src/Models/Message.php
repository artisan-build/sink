<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Models;

use ArtisanBuild\SinkServer\Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected $connection = 'sink';

    protected $guarded = [];

    public function recipients(): HasMany
    {
        return $this->hasMany(MessageRecipient::class);
    }

    public function headers(): HasMany
    {
        return $this->hasMany(MessageHeader::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(MessageLink::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'parsed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): Factory
    {
        return MessageFactory::new();
    }
}
