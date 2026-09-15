<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Notifications\StandalonePasswordResetNotification;
use ArtisanBuild\BuiltForCloud\StandaloneHandoff;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

test('package password reset request screen can be rendered', function (): void {
    $response = $this->get(route('bfc.password.request'))
        ->assertOk()
        ->assertSee('Reset password');

    assertTestMarker($response, 'password-request-form');
});

test('package password reset requests are non-enumerating', function (): void {
    $user = passwordResetUser();
    Notification::fake();

    $known = $this->from(route('bfc.password.request', absolute: false))
        ->post(route('bfc.password.email'), ['email' => $user->email]);
    $missing = $this->from(route('bfc.password.request', absolute: false))
        ->post(route('bfc.password.email'), ['email' => 'missing@example.test']);

    foreach ([$known, $missing] as $response) {
        $response->assertRedirect(route('bfc.password.request', absolute: false))
            ->assertSessionHas('status', 'recovery-requested');
    }

    Notification::assertSentOnDemand(StandalonePasswordResetNotification::class, 1);
    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeTrue();
});

test('package password reset screen can be rendered from a valid handoff', function (): void {
    $user = passwordResetUser();
    $token = 'test-created-reset-token';
    createPasswordResetToken($user, $token);
    beginPasswordResetHandoff($token);

    $response = $this->get(route('bfc.password.reset.form'))
        ->assertOk()
        ->assertSee($user->email);

    assertTestMarker($response, 'password-reset-form');
});

test('package users can reset their password with a valid handoff', function (): void {
    $user = passwordResetUser();
    $token = 'test-created-reset-token';
    $sessionVersion = $user->auth_session_version;
    createPasswordResetToken($user, $token);
    beginPasswordResetHandoff($token);

    $this->post(route('bfc.password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'replacement-password',
        'password_confirmation' => 'replacement-password',
    ])->assertSessionHasNoErrors()
        ->assertRedirect(route('bfc.login'));

    $user->refresh();
    expect(Hash::check('replacement-password', (string) $user->password))->toBeTrue()
        ->and($user->role)->toBe(UserRole::Member->value)
        ->and($user->status)->toBe('active')
        ->and($user->auth_session_version)->toBe($sessionVersion + 1)
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();

    $this->post(route('bfc.login.store'), [
        'email' => $user->email,
        'password' => 'replacement-password',
    ])->assertRedirect(route('bfc.ui.home', absolute: false));
    $this->assertAuthenticatedAs($user);
});

function passwordResetUser(): User
{
    $user = User::query()->create([
        'name' => 'Password Reset User',
        'email' => 'password-reset-'.Str::ulid().'@example.test',
        'password' => Hash::make('test-created-password'),
    ]);
    $user->forceFill([
        'role' => UserRole::Member->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    return $user->refresh();
}

function createPasswordResetToken(User $user, string $token): void
{
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => hash('sha256', $token),
        'created_at' => now(),
    ]);
}

function beginPasswordResetHandoff(string $token): void
{
    $response = test()->get(route('bfc.password.reset', ['token' => $token], false))
        ->assertRedirect(route('bfc.password.reset.form'));
    $cookie = $response->getCookie(StandaloneHandoff::COOKIE);

    expect($cookie)->toBeInstanceOf(Cookie::class);
    test()->withCookie(StandaloneHandoff::COOKIE, $cookie->getValue());
}
