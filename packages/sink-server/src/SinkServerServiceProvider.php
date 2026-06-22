<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer;

use Illuminate\Support\ServiceProvider;

final class SinkServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sink-server.php', SinkServer::CONFIG_KEY);
    }
}
