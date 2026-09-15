<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureStandaloneAuthority;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

test('Sink declares the exact package-owned human auth and UI configuration', function (): void {
    expect(config('auth.providers.users.model'))->toBe(User::class)
        ->and(Schema::hasColumn('users', 'role'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'status'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'is_admin'))->toBeFalse()
        ->and(array_column(UserRole::cases(), 'value'))->toBe(['owner', 'admin', 'member'])
        ->and(RolePolicy::canUseProduct('unknown-role'))->toBeFalse()
        ->and(config('built-for-cloud.manifest'))->toBe([
            'name' => 'Sink',
            'slug' => 'sink',
            'description' => 'Self-hosted, unmetered staging and test mail capture for Laravel.',
            'icon' => 'https://scalpels.app/products/sink/icon.svg',
            'product_url' => 'https://scalpels.app/products/sink',
        ])->and(config('built-for-cloud.credentials.app_purposes'))->toBe([
            'sink.ingest' => 'consumption',
            'sink.mcp' => 'mcp',
        ])->and(config('built-for-cloud.ui'))->toBe([
            'landing_page' => true,
            'member_management' => true,
            'personal_credentials' => false,
            'installation_credentials' => false,
            'session_management' => true,
            'managed_transitions' => true,
            'credential_purposes' => ['sink.ingest', 'sink.mcp'],
        ]);
});

test('Sink no longer owns human authentication artifacts', function (): void {
    $forbiddenClasses = [
        'App\\Actions\\Fortify\\CreateNewUser',
        'App\\Actions\\Fortify\\ResetUserPassword',
        'App\\Http\\Middleware\\AuthenticateConsoleOrLocal',
        'App\\Livewire\\Actions\\Logout',
        'App\\Livewire\\Admin\\Invitations',
        'App\\Livewire\\Auth\\AcceptInvitation',
        'App\\Livewire\\Settings\\DeleteUserForm',
        'App\\Livewire\\Settings\\Profile',
        'App\\Models\\User',
        'App\\Providers\\FortifyServiceProvider',
        'App\\SinkCredentialDeclaration',
    ];

    foreach ($forbiddenClasses as $class) {
        expect(class_exists($class))->toBeFalse($class);
    }

    foreach ([
        app_path('Models/User.php'),
        base_path('database/factories/UserFactory.php'),
        config_path('fortify.php'),
        resource_path('views/layouts/auth.blade.php'),
        resource_path('views/livewire/settings/profile.blade.php'),
        base_path('routes/settings.php'),
    ] as $path) {
        expect(file_exists($path))->toBeFalse($path);
    }

    expect(InstalledVersions::isInstalled('laravel/fortify'))->toBeFalse()
        ->and(Route::has('login'))->toBeFalse()
        ->and(Route::has('register'))->toBeFalse()
        ->and(Route::has('profile.edit'))->toBeFalse();
});

test('the package mounts and serves the standalone human lifecycle', function (): void {
    foreach ([
        'bfc.login',
        'bfc.login.store',
        'bfc.logout',
        'bfc.password.request',
        'bfc.password.email',
        'bfc.password.reset',
        'bfc.password.reset.form',
        'bfc.password.update',
        'bfc.invitations.accept',
        'bfc.invitations.accept.form',
        'bfc.invitations.accept.store',
        'bfc.members.index',
        'bfc.members.invitations.store',
        'bfc.members.role.update',
        'bfc.members.destroy',
        'bfc.sessions.index',
        'bfc.sessions.destroy',
        'bfc.sessions.destroy-others',
    ] as $routeName) {
        expect(Route::has($routeName))->toBeTrue($routeName)
            ->and(Route::getRoutes()->getByName($routeName)?->gatherMiddleware())
            ->toContain(EnsureStandaloneAuthority::class);
    }

    assertTestMarker($this->get(route('bfc.login'))->assertOk(), 'login-form');
    assertTestMarker($this->get(route('bfc.password.request'))->assertOk(), 'password-request-form');

    $owner = User::query()->create([
        'name' => 'Test Created Owner',
        'email' => 'test-created-owner@example.test',
        'password' => Hash::make('test-created-password'),
    ]);
    $owner->forceFill([
        'role' => UserRole::Owner->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    $this->post(route('bfc.login.store'), [
        'email' => $owner->email,
        'password' => 'test-created-password',
    ])->assertRedirect(route('bfc.ui.home', absolute: false));

    $home = $this->get(route('bfc.ui.home'))->assertOk();
    assertTestMarker($home, 'ui-shell');
    assertTestMarker($home, 'ui-nav-member-management');
    assertTestMarker($home, 'ui-nav-session-management');
    assertTestMarker($home, 'ui-nav-managed-transitions');
    assertTestMarker($home, 'ui-nav-personal-credentials', present: false);
    assertTestMarker($home, 'ui-nav-installation-credentials', present: false);

    $members = $this->get(route('bfc.members.index'))->assertOk()->assertSee($owner->email);
    assertTestMarker($members, 'members-management');
    assertTestMarker($members, 'members-item');

    $this->post(route('bfc.logout'))->assertRedirect(route('bfc.login'));
    $this->assertGuest();
});

test('the package create admin command creates the first owner locally', function (): void {
    expect(Artisan::call('create-admin', [
        '--email' => 'command-owner@example.test',
        '--password' => 'test-created-password',
        '--name' => 'Command Owner',
        '--local' => true,
    ]))->toBe(0);

    $owner = User::query()->where('email', 'command-owner@example.test')->sole();

    expect($owner->name)->toBe('Command Owner')
        ->and($owner->role)->toBe(UserRole::Owner->value)
        ->and($owner->status)->toBe('active');
});
