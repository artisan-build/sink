<?php

declare(strict_types=1);

use ArtisanBuild\SinkServer\SinkServer;
use ArtisanBuild\SinkServer\SinkServerServiceProvider;

it('merges its package config through the service provider', function (): void {
    expect(SinkServer::CONFIG_KEY)->toBe('sink-server')
        ->and(config('sink-server.route_prefix'))->toBe('');
});

it('registers the server provider in the test application', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(SinkServerServiceProvider::class);
});
