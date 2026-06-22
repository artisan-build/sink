<?php

use ArtisanBuild\SinkServer\SinkServerServiceProvider;

it('boots the app with the Sink server package provider registered', function (): void {
    $providers = app()->getLoadedProviders();

    expect($providers)->toHaveKey(SinkServerServiceProvider::class);
});
