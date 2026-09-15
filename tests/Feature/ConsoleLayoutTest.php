<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureStandaloneAuthority;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Drawer\Utils;
use Livewire\LivewireManager;

test('production roots use the Sink layout and package authentication middleware', function (): void {
    $dashboardMiddleware = resolve('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('dashboard'));
    $inboxMiddleware = resolve('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('sink.inbox'));
    $membersMiddleware = resolve('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('bfc.members.index'));

    expect(InstalledVersions::getPrettyVersion('artisan-build/built-for-cloud'))->toBe('v0.11.0')
        ->and(config('auth.providers.users.model'))->toBe(User::class)
        ->and(config('livewire.component_layout'))->toBe('layouts.app')
        ->and(realpath(view()->getFinder()->find('layouts.app')))
        ->toBe(realpath(resource_path('views/layouts/app.blade.php')))
        ->and($dashboardMiddleware)->toContain(EnsureUserIsAuthenticated::class)
        ->and($inboxMiddleware)->toContain(EnsureUserIsAuthenticated::class)
        ->and($membersMiddleware)->toContain(EnsureStandaloneAuthority::class, EnsureUserIsAuthenticated::class)
        ->and(class_exists('App\\Http\\Middleware\\AuthenticateConsoleOrLocal'))->toBeFalse()
        ->and(class_exists('App\\Models\\User'))->toBeFalse();
});

test('each package role receives the Sink shell from its normal web session', function (UserRole $role, bool $canManageMembers): void {
    $user = consoleLayoutUser($role);

    consoleLayoutLogin($user);

    $response = $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee($user->name)
        ->assertSee($user->email)
        ->assertDontSeeHtml('data-bfc-console-chrome="1"')
        ->assertDontSee('/bfc/console/chrome.js', false);

    assertTestMarker($response, 'sidebar-dashboard');
    assertTestMarker($response, 'sidebar-inbox');
    assertTestMarker($response, 'sidebar-members', present: $canManageMembers);
    assertTestMarker($response, 'desktop-user-menu');
    assertTestMarker($response, 'desktop-user-menu-trigger');
    assertTestMarker($response, 'desktop-user-menu-account');
    assertTestMarker($response, 'desktop-user-menu-logout');
    assertTestMarker($response, 'mobile-user-menu');
    assertTestMarker($response, 'mobile-user-menu-account');
    assertTestMarker($response, 'mobile-user-menu-logout');
    assertTestMarker($response, 'desktop-user-menu-settings', present: false);
    assertTestMarker($response, 'mobile-user-menu-settings', present: false);

    $desktopMenu = [];
    preg_match('/<ui-dropdown\b(?=[^>]*\bdata-testid="desktop-user-menu")[^>]*>/', (string) $response->getContent(), $desktopMenu);
    $desktopMenuClasses = [];
    preg_match('/\bclass="([^"]*)"/', $desktopMenu[0] ?? '', $desktopMenuClasses);

    expect(preg_split('/\s+/', trim($desktopMenuClasses[1] ?? '')))
        ->toContain('hidden', 'lg:block');
})->with([
    'owner' => [UserRole::Owner, true],
    'admin' => [UserRole::Admin, true],
    'member' => [UserRole::Member, false],
]);

test('the package account surface exposes the configured lifecycle navigation by role', function (UserRole $role, bool $canManageMembers, bool $canManageAuthority): void {
    $user = consoleLayoutUser($role);

    consoleLayoutLogin($user);

    $response = $this->get(route('bfc.ui.home'))
        ->assertOk()
        ->assertSee('Sink')
        ->assertSee('Self-hosted, unmetered staging and test mail capture for Laravel.');

    assertTestMarker($response, 'ui-shell');
    assertTestMarker($response, 'ui-manifest');
    assertTestMarker($response, 'ui-navigation');
    assertTestMarker($response, 'ui-nav-member-management', present: $canManageMembers);
    assertTestMarker($response, 'ui-nav-session-management');
    assertTestMarker($response, 'ui-nav-managed-transitions', present: $canManageAuthority);
    assertTestMarker($response, 'ui-nav-personal-credentials', present: false);
    assertTestMarker($response, 'ui-nav-installation-credentials');
    assertTestMarker($response, 'ui-logout-form');
})->with([
    'owner' => [UserRole::Owner, true, true],
    'admin' => [UserRole::Admin, true, false],
    'member' => [UserRole::Member, false, false],
]);

test('member management follows the package role policy', function (UserRole $role): void {
    $user = consoleLayoutUser($role);

    consoleLayoutLogin($user);

    $response = $this->get(route('bfc.members.index'))
        ->assertOk()
        ->assertSee($user->email);

    assertTestMarker($response, 'members-management');
    assertTestMarker($response, 'members-item');
})->with([
    'owner' => [UserRole::Owner],
    'admin' => [UserRole::Admin],
    'member' => [UserRole::Member],
]);

test('hostile package identity values stay escaped in the Sink shell', function (): void {
    $hostileName = '<img src=x onerror=alert(1)>" onmouseover="alert(2)';
    $user = consoleLayoutUser(UserRole::Admin, name: $hostileName);

    consoleLayoutLogin($user);

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();
    $escapedControl = Blade::render('<span title="{{ $value }}">{{ $value }}</span>', ['value' => $hostileName]);
    $rawDecoy = Blade::render('<span title="{!! $value !!}">{!! $value !!}</span>', ['value' => $hostileName]);

    expect($html)->toContain(e($hostileName))
        ->not->toContain('<img')
        ->not->toContain('" onmouseover="')
        ->and($escapedControl)->not->toContain('<img')
        ->and($rawDecoy)->toContain('<img');
});

test('a package web session survives a real Sink Livewire update without a delegated bridge', function (): void {
    ensureConsoleLayoutMessagesTable();
    $user = consoleLayoutUser(UserRole::Member);

    consoleLayoutLogin($user);

    $page = $this->get(route('sink.inbox'))->assertOk();
    $snapshot = Utils::extractAttributeDataFromHtml($page->getContent(), 'wire:snapshot');

    Auth::forgetGuards();

    $this->postJson(resolve(LivewireManager::class)->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'updates' => [],
            'calls' => [[
                'path' => '',
                'method' => '$refresh',
                'params' => [],
            ]],
        ]],
    ], ['X-Livewire' => 'true'])
        ->assertOk()
        ->assertHeaderMissing('BFC-Console-Reentry');
});

test('the Sink shell logs out through the package lifecycle', function (): void {
    $user = consoleLayoutUser(UserRole::Owner);

    consoleLayoutLogin($user);

    $dashboard = $this->get(route('dashboard'))->assertOk();
    $dashboard->assertSee(route('bfc.ui.logout'), false);

    $this->post(route('bfc.ui.logout'))
        ->assertRedirect(route('bfc.landing'));

    $this->assertGuest();
});

function consoleLayoutUser(UserRole $role, ?string $name = null): User
{
    $user = User::query()->create([
        'name' => $name ?? 'Console Layout '.ucfirst($role->value),
        'email' => 'console-layout-'.$role->value.'-'.Str::ulid().'@example.test',
        'password' => Hash::make('test-created-password'),
    ]);
    $user->forceFill([
        'role' => $role->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    return $user;
}

function consoleLayoutLogin(User $user): void
{
    test()->post(route('bfc.login.store'), [
        'email' => $user->email,
        'password' => 'test-created-password',
    ])->assertRedirect(route('bfc.ui.home', absolute: false));
}

function ensureConsoleLayoutMessagesTable(): void
{
    $connection = (string) config('sink-server.database.connection');

    if (Schema::connection($connection)->hasTable('messages')) {
        return;
    }

    Artisan::call('migrate', [
        '--database' => $connection,
        '--path' => 'packages/sink-server/database/migrations',
        '--realpath' => true,
    ]);
}
