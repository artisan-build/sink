<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

test('the public root is the package-owned Sink landing page', function (): void {
    $response = $this->get('/')->assertOk()
        ->assertSee('Sink')
        ->assertSee('Self-hosted, unmetered staging and test mail capture for Laravel.')
        ->assertSee(route('bfc.ui.home'), false);

    expect(Route::getRoutes()->getByName('bfc.landing')?->uri())->toBe('/');
    assertTestMarker($response, 'landing');
    assertTestMarker($response, 'landing-ui-entry');
});
