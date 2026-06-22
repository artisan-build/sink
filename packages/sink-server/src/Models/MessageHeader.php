<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Models;

use ArtisanBuild\SinkServer\Database\Factories\MessageHeaderFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MessageHeader extends Model
{
    /** @use HasFactory<MessageHeaderFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $connection = 'sink';

    protected $guarded = [];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    protected static function newFactory(): Factory
    {
        return MessageHeaderFactory::new();
    }
}
