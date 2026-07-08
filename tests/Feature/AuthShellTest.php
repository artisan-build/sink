<?php

use App\Livewire\Admin\Invitations;
use App\Livewire\Auth\AcceptInvitation;
use App\Models\User;
use ArtisanBuild\BuiltForCloud\Invitation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('users table has is admin and user casts it to boolean', function (): void {
    expect(Schema::hasColumn('users', 'is_admin'))->toBeTrue();

    $user = User::factory()->create();

    expect($user->refresh()->is_admin)->toBeFalse()
        ->and($user->is_admin)->toBeBool();

    $user->forceFill(['is_admin' => true])->save();

    expect($user->refresh()->is_admin)->toBeTrue()
        ->and($user->is_admin)->toBeBool();
});

test('open registration is disabled and does not create a user', function (): void {
    expect(Features::enabled(Features::registration()))->toBeFalse();

    $this->post('/register', [
        'name' => 'Open User',
        'email' => 'open@example.com',
        'password' => 'secret-pass',
        'password_confirmation' => 'secret-pass',
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', [
        'email' => 'open@example.com',
    ]);
});

test('an invited user can accept an invitation and is logged in as a non admin', function (): void {
    $invitation = Invitation::invite('invitee@test');

    $this->get(route('register.invitation', $invitation->token))
        ->assertOk()
        ->assertSee('Accept your invitation')
        ->assertSee('invitee@test');

    Livewire::test(AcceptInvitation::class, ['token' => $invitation->token])
        ->set('name', 'Invited User')
        ->set('password', 'secret-pass')
        ->set('password_confirmation', 'secret-pass')
        ->call('accept')
        ->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'invitee@test')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    expect($user->is_admin)->toBeFalse();
    expect($invitation->refresh()->accepted_at)->not->toBeNull();
});

test('invalid expired and already accepted invitations do not show an open signup form', function (): void {
    $expiredInvitation = Invitation::invite('expired@test', expiresAt: now()->subMinute());

    $this->get(route('register.invitation', 'unknown-token'))
        ->assertOk()
        ->assertSee('Invitation invalid or expired')
        ->assertDontSee('Create account');

    $this->get(route('register.invitation', $expiredInvitation->token))
        ->assertOk()
        ->assertSee('Invitation invalid or expired')
        ->assertDontSee('Create account');

    $acceptedInvitation = Invitation::invite('accepted@test');

    Invitation::accept($acceptedInvitation->token, [
        'name' => 'Accepted User',
        'password' => 'secret-pass',
    ]);

    $this->get(route('register.invitation', $acceptedInvitation->token))
        ->assertOk()
        ->assertSee('Invitation invalid or expired')
        ->assertDontSee('Create account');

    expect(User::query()->whereIn('email', [
        'expired@test',
        'unknown@test',
    ])->count())->toBe(0)
        ->and(User::query()->where('email', 'accepted@test')->count())->toBe(1);
});

test('invitation accept guard properties cannot be forced from the client', function (): void {
    $userCount = User::query()->count();

    try {
        Livewire::test(AcceptInvitation::class, ['token' => 'bogus-token'])
            ->set('name', 'Forced User')
            ->set('password', 'secret-pass')
            ->set('password_confirmation', 'secret-pass')
            ->set('validInvitation', true)
            ->set('token', 'bogus-token')
            ->call('accept');
    } catch (CannotUpdateLockedPropertyException $exception) {
        expect($exception->property)->toBeIn(['validInvitation', 'token']);
    }

    $this->assertDatabaseCount('users', $userCount);
    $this->assertGuest();
});

test('admin invitations page is admin only and creates invitations', function (): void {
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($admin)
        ->get(route('invitations'))
        ->assertOk()
        ->assertSee('Invitations');

    Livewire::actingAs($admin)
        ->test(Invitations::class)
        ->set('email', 'new-user@test')
        ->call('createInvitation')
        ->assertSet('email', '')
        ->assertSet('invitationLink', fn (?string $link): bool => is_string($link) && str_contains($link, '/register/'));

    $invitation = Invitation::query()->where('email', 'new-user@test')->firstOrFail();

    expect($invitation->accepted_at)->toBeNull()
        ->and($invitation->token)->not->toBeEmpty();
});

test('non admins and guests cannot visit the invitations page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('invitations'))
        ->assertForbidden();

    auth()->logout();

    $this->get(route('invitations'))
        ->assertRedirect(route('login'));
});

test('create admin command creates an admin user', function (): void {
    $exitCode = Artisan::call('create-admin', [
        '--email' => 'admin@test.com',
        '--password' => 'secret-pass',
        '--name' => 'Admin',
        '--local' => true,
    ]);

    expect($exitCode)->toBe(0);

    $admin = User::query()->where('email', 'admin@test.com')->firstOrFail();

    expect($admin->is_admin)->toBeTrue();
});
