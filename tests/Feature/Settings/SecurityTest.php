<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

test('Sink-owned security settings remain absent', function (): void {
    expect(Route::has('security.edit'))->toBeFalse()
        ->and(class_exists('App\\Livewire\\Settings\\Security'))->toBeFalse();

    $this->get('/settings/security')->assertNotFound();
});

test('package passwords cannot be changed through a Sink settings endpoint', function (): void {
    $user = securityBoundaryUser();
    securityBoundaryLogin($user);
    $password = $user->password;

    $this->put('/user/password', [
        'current_password' => 'test-created-password',
        'password' => 'changed-password',
        'password_confirmation' => 'changed-password',
    ])->assertNotFound();

    expect($user->refresh()->password)->toBe($password)
        ->and(Hash::check('test-created-password', $user->password))->toBeTrue();
});

test('Sink does not expose app-owned two-factor authentication', function (): void {
    $user = securityBoundaryUser();
    securityBoundaryLogin($user);

    $this->post('/user/two-factor-authentication')->assertNotFound();
    $this->assertAuthenticatedAs($user);
});

test('Sink does not expose app-owned passkey management', function (): void {
    $user = securityBoundaryUser();
    securityBoundaryLogin($user);

    $this->post('/user/passkeys')->assertNotFound();
    $this->assertAuthenticatedAs($user);
});

test('password recovery remains on the package-owned lifecycle', function (): void {
    $response = $this->get(route('bfc.password.request'))
        ->assertOk()
        ->assertSee('Reset password');

    assertTestMarker($response, 'password-request-form');
});

test('session security remains on the package-owned lifecycle', function (): void {
    $user = securityBoundaryUser();
    securityBoundaryLogin($user);

    $home = $this->get(route('bfc.ui.home'))->assertOk();
    assertTestMarker($home, 'ui-nav-session-management');

    $sessions = $this->get(route('bfc.sessions.index'))
        ->assertOk()
        ->assertSee('Account security')
        ->assertSee('This session driver cannot enumerate account sessions.');

    assertTestMarker($sessions, 'sessions-management');
    assertTestMarker($sessions, 'sessions-unavailable');
});

function securityBoundaryUser(): User
{
    $user = User::query()->create([
        'name' => 'Security Boundary Member',
        'email' => 'security-boundary-'.Str::ulid().'@example.test',
        'password' => Hash::make('test-created-password'),
    ]);
    $user->forceFill([
        'role' => UserRole::Member->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    return $user;
}

function securityBoundaryLogin(User $user): void
{
    test()->post(route('bfc.login.store'), [
        'email' => $user->email,
        'password' => 'test-created-password',
    ])->assertRedirect(route('bfc.ui.home', absolute: false));
}
