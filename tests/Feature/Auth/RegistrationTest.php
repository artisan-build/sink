<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Invitation;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\StandaloneHandoff;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;

test('Sink does not expose open registration', function (): void {
    expect(Route::has('register'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Uninvited User',
        'email' => 'uninvited@example.test',
        'password' => 'test-created-password',
        'password_confirmation' => 'test-created-password',
    ])->assertNotFound();

    expect(User::query()->where('email', 'uninvited@example.test')->exists())->toBeFalse();
});

test('an addressed package invitation creates and authenticates a member', function (): void {
    $token = 'test-created-invitation-token';
    $invitation = Invitation::query()->create([
        'email' => 'invited-member@example.test',
        'token' => Invitation::hashToken($token),
        'role' => UserRole::Member->value,
        'expires_at' => now()->addHour(),
    ]);

    beginInvitationHandoff($token);

    $form = $this->get(route('bfc.invitations.accept.form'))
        ->assertOk()
        ->assertSee($invitation->email);
    assertTestMarker($form, 'invitation-accept-form');

    $this->post(route('bfc.invitations.accept.store'), [
        'token' => $token,
        'name' => 'Invited Member',
        'password' => 'invitation-password',
        'password_confirmation' => 'invitation-password',
    ])->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $user = User::query()->where('email', $invitation->email)->sole();
    expect($user->name)->toBe('Invited Member')
        ->and($user->role)->toBe(UserRole::Member->value)
        ->and($user->status)->toBe('active')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('invitation-password', (string) $user->password))->toBeTrue()
        ->and($invitation->refresh()->used_by)->toBe((string) $user->getKey())
        ->and($invitation->accepted_at)->not->toBeNull()
        ->and(session(StandaloneAccess::SESSION_VERSION_KEY))->toBe($user->auth_session_version);
    $this->assertAuthenticatedAs($user);
});

function beginInvitationHandoff(string $token): void
{
    $response = test()->get(route('bfc.invitations.accept', ['token' => $token], false))
        ->assertRedirect(route('bfc.invitations.accept.form'));
    $cookie = $response->getCookie(StandaloneHandoff::COOKIE);

    expect($cookie)->toBeInstanceOf(Cookie::class);
    test()->withCookie(StandaloneHandoff::COOKIE, $cookie->getValue());
}
