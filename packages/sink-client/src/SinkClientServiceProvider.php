<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkClient;

use Illuminate\Support\ServiceProvider;

final class SinkClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sink-client.php', SinkClient::CONFIG_KEY);
    }
}
