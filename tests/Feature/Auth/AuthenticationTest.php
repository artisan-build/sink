<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

test('package login screen can be rendered', function (): void {
    $response = $this->get(route('bfc.login'))
        ->assertOk()
        ->assertSee('Sign in')
        ->assertSee(route('bfc.password.request'), false);

    assertTestMarker($response, 'login-form');
});

test('package users can authenticate through the standalone login', function (): void {
    $user = authenticationUser();

    $this->post(route('bfc.login.store'), [
        'email' => $user->email,
        'password' => 'test-created-password',
    ])->assertSessionHasNoErrors()
        ->assertRedirect(route('bfc.ui.home', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(session(StandaloneAccess::SESSION_VERSION_KEY))->toBe($user->auth_session_version)
        ->and($user->refresh()->last_authenticated_at)->not->toBeNull();
});

test('package users cannot authenticate with an invalid password', function (): void {
    $user = authenticationUser();

    $this->from(route('bfc.login', absolute: false))
        ->post(route('bfc.login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertRedirect(route('bfc.login', absolute: false))
        ->assertSessionHasErrors(['email']);

    $this->assertGuest();
    expect(session()->has(StandaloneAccess::SESSION_VERSION_KEY))->toBeFalse();
});

test('inactive package users cannot authenticate', function (): void {
    $user = authenticationUser();
    $user->forceFill(['status' => 'inactive'])->save();

    $this->post(route('bfc.login.store'), [
        'email' => $user->email,
        'password' => 'test-created-password',
    ])->assertSessionHasErrors(['email']);

    $this->assertGuest();
});

test('package users can logout from a real versioned web session', function (): void {
    $user = authenticationUser();
    authenticationLogin($user);

    $this->post(route('bfc.logout'))
        ->assertRedirect(route('bfc.login'));

    $this->assertGuest();
    expect(session()->has(StandaloneAccess::SESSION_VERSION_KEY))->toBeFalse();
});

function authenticationUser(): User
{
    $user = User::query()->create([
        'name' => 'Authentication User',
        'email' => 'authentication-'.Str::ulid().'@example.test',
        'password' => Hash::make('test-created-password'),
    ]);
    $user->forceFill([
        'role' => UserRole::Member->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    return $user->refresh();
}

function authenticationLogin(User $user): void
{
    test()->post(route('bfc.login.store'), [
        'email' => $user->email,
        'password' => 'test-created-password',
    ])->assertRedirect(route('bfc.ui.home', absolute: false));
}
