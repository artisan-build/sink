<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Models\Concerns;

trait UsesSinkConnection
{
    public function getConnectionName(): ?string
    {
        return (string) config('sink-server.database.connection', 'sink');
    }
}
