<?php

declare(strict_types=1);

use ArtisanBuild\SinkClient\SinkClient;
use ArtisanBuild\SinkClient\SinkClientServiceProvider;

it('merges its package config through the service provider', function (): void {
    expect(SinkClient::CONFIG_KEY)->toBe('sink-client')
        ->and(config('sink-client.transport'))->toBe('sink');
});

it('registers the client provider in the test application', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(SinkClientServiceProvider::class);
});
