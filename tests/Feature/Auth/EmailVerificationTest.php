<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

test('Sink does not expose an email verification screen', function (): void {
    expect(Route::has('verification.notice'))->toBeFalse();

    $this->get('/email/verify')->assertNotFound();
});

test('Sink does not expose email verification links', function (): void {
    $user = emailVerificationUser();

    expect(Route::has('verification.verify'))->toBeFalse();

    $this->get('/email/verify/'.$user->getKey().'/'.sha1($user->email))->assertNotFound();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('invalid legacy verification links cannot change package identity', function (): void {
    $user = emailVerificationUser();

    $this->get('/email/verify/'.$user->getKey().'/'.sha1('wrong-email'))->assertNotFound();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('Sink does not expose email verification notifications', function (): void {
    $user = emailVerificationUser();
    Notification::fake();

    expect(Route::has('verification.send'))->toBeFalse();

    $this->post('/email/verification-notification')->assertNotFound();

    Notification::assertNothingSent();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

function emailVerificationUser(): User
{
    $user = User::query()->create([
        'name' => 'Unverified User',
        'email' => 'unverified-'.Str::ulid().'@example.test',
        'password' => Hash::make('test-created-password'),
    ]);
    $user->forceFill([
        'role' => UserRole::Member->value,
        'status' => 'active',
        'email_verified_at' => null,
    ])->save();

    return $user;
}
