<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Auth\CredentialResolver;
use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureStandaloneAuthority;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use ArtisanBuild\SinkContracts\Envelope;
use ArtisanBuild\SinkContracts\Truncation;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
            'installation_credentials' => true,
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
    assertTestMarker($home, 'ui-nav-installation-credentials');

    $members = $this->get(route('bfc.members.index'))->assertOk()->assertSee($owner->email);
    assertTestMarker($members, 'members-management');
    assertTestMarker($members, 'members-item');

    $this->post(route('bfc.logout'))->assertRedirect(route('bfc.login'));
    $this->assertGuest();
});

test('every recognized role can reach and manage installation credentials', function (UserRole $role): void {
    $user = User::query()->create([
        'name' => 'Credential '.$role->value,
        'email' => 'credential-'.$role->value.'@example.test',
        'password' => Hash::make('test-created-password'),
    ]);
    $user->forceFill([
        'role' => $role->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    $this->post(route('bfc.login.store'), [
        'email' => $user->email,
        'password' => 'test-created-password',
    ])->assertRedirect(route('bfc.ui.home', absolute: false));

    $page = $this->get(route('bfc.ui.installation-credentials.index'))->assertOk();
    assertTestMarker($page, 'installation-credentials');
    assertTestMarker($page, 'installation-credentials-issue-option');

    $response = $this->postJson('/bfc/installation/credentials', [
        'subject_type' => SubjectType::Installation->value,
        'subject_ref' => 'sink-'.$role->value,
        'kind' => CredentialKind::Bearer->value,
        'purpose' => CredentialPurpose::Consumption->value,
        'name' => $role->value.' ingest',
    ])->assertCreated();
    $credential = Credential::query()->findOrFail($response->json('credential.id'));

    expect($credential->user_id)->toBeNull()
        ->and($credential->purpose)->toBe(CredentialPurpose::Consumption)
        ->and($credential->subject_type)->toBe(SubjectType::Installation);
})->with(UserRole::cases());

test('installation ingest and MCP credentials survive issuer departure and authority mode changes', function (): void {
    $issuer = User::query()->create([
        'name' => 'Credential issuer',
        'email' => 'credential-issuer@example.test',
        'password' => Hash::make('test-created-password'),
    ]);
    $issuer->forceFill([
        'role' => UserRole::Member->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    $this->post(route('bfc.login.store'), [
        'email' => $issuer->email,
        'password' => 'test-created-password',
    ])->assertRedirect(route('bfc.ui.home', absolute: false));

    $issued = [];
    foreach ([
        'sink.ingest' => CredentialPurpose::Consumption,
        'sink.mcp' => CredentialPurpose::Mcp,
    ] as $name => $purpose) {
        $response = $this->postJson('/bfc/installation/credentials', [
            'subject_type' => SubjectType::Installation->value,
            'subject_ref' => 'surviving-installation',
            'kind' => CredentialKind::Bearer->value,
            'purpose' => $purpose->value,
            'name' => $name,
        ])->assertCreated();
        $issued[$purpose->value] = (string) $response->json('delivery.secret');
    }

    $issuer->forceFill(['status' => 'inactive'])->save();
    expect(InstallationAuthority::change(InstallationAuthority::current(), AuthorityMode::Managed))->not->toBeNull();

    $payload = Envelope::make(
        idempotencyKey: (string) Str::ulid(),
        sentAt: now()->toIso8601String(),
        message: base64_encode("From: sender@example.test\r\nTo: recipient@example.test\r\nSubject: survives\r\n\r\nBody."),
        stream: null,
        truncation: Truncation::None,
    )->toArray();
    $this->postJson('/ingest', $payload, [
        'Authorization' => 'Bearer '.$issued[CredentialPurpose::Consumption->value],
    ])->assertAccepted();
    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 'survival',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'sink-tests', 'version' => '1.0.0'],
        ],
    ], ['Authorization' => 'Bearer '.$issued[CredentialPurpose::Mcp->value]])->assertOk();

    foreach ($issued as $purpose => $secret) {
        $credential = resolve(CredentialResolver::class)->resolve(CredentialKind::Bearer, $secret);
        expect($credential?->purpose->value)->toBe($purpose)
            ->and($credential?->user_id)->toBeNull()
            ->and($credential?->last_used_at)->not->toBeNull();
    }
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
