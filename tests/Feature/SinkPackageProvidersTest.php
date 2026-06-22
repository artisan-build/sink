<?php

use ArtisanBuild\SinkClient\SinkClientServiceProvider;
use ArtisanBuild\SinkServer\SinkServerServiceProvider;

it('boots the app with both Sink package providers registered', function (): void {
    $providers = app()->getLoadedProviders();

    expect($providers)->toHaveKey(SinkClientServiceProvider::class)
        ->and($providers)->toHaveKey(SinkServerServiceProvider::class);
});
