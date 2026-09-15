<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

test('Sink does not expose two factor authentication', function (): void {
    expect(Route::has('two-factor.login'))->toBeFalse();

    $this->get('/two-factor-challenge')->assertNotFound();
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertNotFound();
});

test('package users have no two factor authentication state', function (): void {
    expect(Schema::hasColumn('users', 'two_factor_secret'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'two_factor_recovery_codes'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'two_factor_confirmed_at'))->toBeFalse();
});
