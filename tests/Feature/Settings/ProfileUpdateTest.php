<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

test('Sink-owned profile settings remain absent', function (): void {
    expect(Route::has('profile.edit'))->toBeFalse()
        ->and(class_exists('App\\Livewire\\Settings\\Profile'))->toBeFalse()
        ->and(class_exists('App\\Livewire\\Settings\\DeleteUserForm'))->toBeFalse();

    $this->get('/settings/profile')->assertNotFound();
});

test('the Sink shell presents package identity as read-only account information', function (): void {
    $user = profileBoundaryUser(UserRole::Member, 'Read Only Identity');
    profileBoundaryLogin($user);

    $response = $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee($user->name)
        ->assertSee($user->email)
        ->assertSee(route('bfc.ui.home'), false);

    assertTestMarker($response, 'desktop-user-menu-account');
    assertTestMarker($response, 'mobile-user-menu-account');
    assertTestMarker($response, 'desktop-user-menu-settings', present: false);
    assertTestMarker($response, 'mobile-user-menu-settings', present: false);
});

test('package identity cannot be updated through a Sink profile endpoint', function (): void {
    $user = profileBoundaryUser(UserRole::Member, 'Original Identity');
    profileBoundaryLogin($user);

    $this->patch('/settings/profile', [
        'name' => 'Changed Identity',
        'email' => 'changed-identity@example.test',
    ])->assertNotFound();

    expect($user->refresh()->name)->toBe('Original Identity')
        ->and($user->email)->not->toBe('changed-identity@example.test')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('package users cannot delete themselves through Sink profile settings', function (): void {
    $user = profileBoundaryUser(UserRole::Member, 'Retained Identity');
    profileBoundaryLogin($user);

    $this->delete('/settings/profile', [
        'password' => 'test-created-password',
    ])->assertNotFound();

    $this->assertModelExists($user);
    $this->assertAuthenticatedAs($user);
});

test('an administrator deactivates members through the package lifecycle', function (): void {
    $owner = profileBoundaryUser(UserRole::Owner, 'Membership Owner');
    $member = profileBoundaryUser(UserRole::Member, 'Managed Member');
    profileBoundaryLogin($owner);

    $members = $this->get(route('bfc.members.index'))
        ->assertOk()
        ->assertSee($member->name)
        ->assertSee($member->email)
        ->assertSee(route('bfc.members.destroy', $member), false);

    assertTestMarker($members, 'members-deactivation-form');

    $this->from(route('bfc.members.index'))
        ->delete(route('bfc.members.destroy', $member))
        ->assertRedirect(route('bfc.members.index'));

    expect($member->refresh()->status)->toBe('inactive')
        ->and($member->deactivated_at)->not->toBeNull();
    $this->assertAuthenticatedAs($owner);
});

function profileBoundaryUser(UserRole $role, string $name): User
{
    $user = User::query()->create([
        'name' => $name,
        'email' => 'profile-boundary-'.$role->value.'-'.Str::ulid().'@example.test',
        'password' => Hash::make('test-created-password'),
    ]);
    $user->forceFill([
        'role' => $role->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    return $user;
}

function profileBoundaryLogin(User $user): void
{
    test()->post(route('bfc.login.store'), [
        'email' => $user->email,
        'password' => 'test-created-password',
    ])->assertRedirect(route('bfc.ui.home', absolute: false));
}
