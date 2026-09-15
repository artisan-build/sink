<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

test('Sink does not expose reusable password confirmation', function (): void {
    expect(Route::has('password.confirm'))->toBeFalse()
        ->and(Route::has('password.confirm.store'))->toBeFalse();

    $this->get('/confirm-password')->assertNotFound();
    $this->post('/user/confirm-password', [
        'password' => 'test-created-password',
    ])->assertNotFound();
});
