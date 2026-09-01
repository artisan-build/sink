<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Models;

use ArtisanBuild\SinkServer\Models\Concerns\UsesSinkConnection;
use Illuminate\Database\Eloquent\Model;

final class MessageBlobCleanupIntent extends Model
{
    use UsesSinkConnection;

    protected $guarded = [];
}
