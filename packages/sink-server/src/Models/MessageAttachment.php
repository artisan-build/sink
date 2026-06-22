<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Models;

use ArtisanBuild\SinkServer\Database\Factories\MessageAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MessageAttachment extends Model
{
    /** @use HasFactory<MessageAttachmentFactory> */
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
        return MessageAttachmentFactory::new();
    }
}
