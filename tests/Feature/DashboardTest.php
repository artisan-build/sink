<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

test('guests are redirected to the login page', function (): void {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('bfc.login'));
});

test('authenticated users can visit the dashboard', function (): void {
    $user = dashboardUser(UserRole::Member);
    dashboardLogin($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

function dashboardUser(UserRole $role): User
{
    $user = User::query()->create([
        'name' => 'Dashboard '.ucfirst($role->value),
        'email' => 'dashboard-'.$role->value.'-'.Str::ulid().'@example.test',
        'password' => Hash::make('test-created-password'),
    ]);
    $user->forceFill([
        'role' => $role->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    return $user;
}

function dashboardLogin(User $user): void
{
    test()->post(route('bfc.login.store'), [
        'email' => $user->email,
        'password' => 'test-created-password',
    ])->assertRedirect(route('bfc.ui.home', absolute: false));
}
