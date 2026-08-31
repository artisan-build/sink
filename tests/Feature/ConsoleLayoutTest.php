<?php

use App\Http\Middleware\AuthenticateConsoleOrLocal;
use App\Models\User;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Console\ConsoleGuard;
use ArtisanBuild\BuiltForCloud\Console\ConsoleGuardConfiguration;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\ConsoleSession;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Drawer\Utils;
use Livewire\LivewireManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

final readonly class ConsoleLayoutActingPrincipalObserver
{
    public function __construct(private Closure $capture) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolver = resolve(ActingPrincipalResolver::class);

        ($this->capture)($resolver->resolve(), $resolver->resolve());

        return $next($request);
    }
}

beforeEach(function (): void {
    config([
        'built-for-cloud.console.issuer' => 'https://scalpels.test',
        'built-for-cloud.console.reentry_url' => 'https://scalpels.test/console/enter',
    ]);
});

test('production roots use the one BfC layout and dual-principal middleware', function (): void {
    $dashboard = Route::getRoutes()->getByName('dashboard');
    $dashboardMiddleware = resolve('router')->gatherRouteMiddleware($dashboard);
    $inboxMiddleware = resolve('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('sink.inbox'));
    $invitationsMiddleware = resolve('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('invitations'));

    expect(InstalledVersions::getPrettyVersion('artisan-build/built-for-cloud'))->toBe('v0.6.1')
        ->and(InstalledVersions::getReference('artisan-build/built-for-cloud'))
        ->toBe('2de534142f784700b2a628b69af43bc99cfe9783')
        ->and(config('livewire.component_layout'))->toBe('bfc::layout')
        ->and(realpath(view()->getFinder()->find('bfc::layout')))
        ->toBe(realpath(resource_path('views/vendor/bfc/layout.blade.php')))
        ->and($dashboardMiddleware)->toContain(AuthenticateConsoleOrLocal::class)
        ->and(array_search(AuthenticateConsoleOrLocal::class, $dashboardMiddleware, true))
        ->toBeLessThan(array_search(SubstituteBindings::class, $dashboardMiddleware, true))
        ->and($inboxMiddleware)->toContain(AuthenticateConsoleOrLocal::class)
        ->and($invitationsMiddleware)->toContain(AuthenticateConsoleOrLocal::class)
        ->and(file_get_contents(resource_path('views/dashboard.blade.php')))
        ->toContain("@extends('bfc::layout'")
        ->and(file_exists(resource_path('views/layouts/app.blade.php')))->toBeFalse();
});

test('a local Fortify user receives the Sink shell with zero Console chrome', function (): void {
    $user = consoleLayoutUser(name: 'Local Sink Admin', email: 'local-admin@example.test', isAdmin: true);

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $response
        ->assertSee('Local Sink Admin')
        ->assertSee('local-admin@example.test')
        ->assertDontSeeHtml('data-bfc-console-chrome="1"')
        ->assertDontSee('/bfc/console/chrome.js', false);

    assertTestMarker($response, 'sidebar-dashboard');
    assertTestMarker($response, 'sidebar-inbox');
    assertTestMarker($response, 'sidebar-invitations');
    assertTestMarker($response, 'desktop-user-menu');
    assertTestMarker($response, 'desktop-user-menu-settings');
    assertTestMarker($response, 'desktop-user-menu-logout');
    assertTestMarker($response, 'mobile-user-menu');
    assertTestMarker($response, 'mobile-user-menu-settings');
    assertTestMarker($response, 'mobile-user-menu-logout');

    $desktopMenu = [];
    preg_match('/<ui-dropdown\b(?=[^>]*\bdata-testid="desktop-user-menu")[^>]*>/', (string) $response->getContent(), $desktopMenu);
    $desktopMenuClasses = [];
    preg_match('/\bclass="([^"]*)"/', $desktopMenu[0] ?? '', $desktopMenuClasses);

    expect(preg_split('/\s+/', trim($desktopMenuClasses[1] ?? '')))
        ->toContain('hidden', 'lg:block');
});

test('a delegated session receives bare Sink navigation with full Console attribution', function (): void {
    $actor = consoleLayoutActor(ConsoleRole::Member);

    $response = $this->withSession(consoleLayoutSession(
        $actor,
        ConsoleRole::Admin,
        displayName: 'Delegated Operator',
        agency: 'Acme Agency',
    ))->get(route('dashboard'))->assertOk();

    $response
        ->assertSeeHtml('data-bfc-console-chrome="1"')
        ->assertSeeHtml('data-bfc-console-role="admin"')
        ->assertSee('Delegated Operator')
        ->assertSee('Acme Agency')
        ->assertSee('/bfc/console/chrome.js', false);

    assertTestMarker($response, 'sidebar-dashboard');
    assertTestMarker($response, 'sidebar-inbox');
    assertTestMarker($response, 'sidebar-invitations');
    assertTestMarker($response, 'desktop-user-menu', present: false);
    assertTestMarker($response, 'desktop-user-menu-settings', present: false);
    assertTestMarker($response, 'desktop-user-menu-logout', present: false);
    assertTestMarker($response, 'mobile-user-menu', present: false);
    assertTestMarker($response, 'mobile-user-menu-settings', present: false);
    assertTestMarker($response, 'mobile-user-menu-logout', present: false);
});

test('the production invitations route admits a local admin', function (): void {
    $admin = consoleLayoutUser(name: 'Local Invitations Admin', email: 'local-invitations@example.test', isAdmin: true);

    $this->actingAs($admin)
        ->get(route('invitations'))
        ->assertOk();
});

test('the production invitations route admits a delegated admin', function (): void {
    $actor = consoleLayoutActor(ConsoleRole::Member);

    $this->withSession(consoleLayoutSession($actor, ConsoleRole::Admin))
        ->get(route('invitations'))
        ->assertOk();
});

test('the production invitations route denies a delegated member if bfc#56 session eviction is skipped', function (): void {
    $localAdmin = consoleLayoutUser(name: 'Local Invitations Decoy', email: 'local-invitations-decoy@example.test', isAdmin: true);
    $actor = consoleLayoutActor(ConsoleRole::Admin);

    $this->actingAs($localAdmin)
        ->withSession(consoleLayoutSession($actor, ConsoleRole::Member))
        ->get(route('invitations'))
        ->assertForbidden();
});

test('the production dashboard resolves one delegated acting principal for downstream middleware', function (): void {
    $actor = consoleLayoutActor(ConsoleRole::Member);
    $observation = [];

    app()->instance(
        ConsoleLayoutActingPrincipalObserver::class,
        new ConsoleLayoutActingPrincipalObserver(
            function (ActingPrincipal $first, ActingPrincipal $second) use (&$observation): void {
                $observation = [
                    'identifier' => $first->identifier(),
                    'delegated' => $first->delegated,
                    'same_instance' => $first === $second,
                ];
            },
        ),
    );

    Route::getRoutes()
        ->getByName('dashboard')
        ->middleware(ConsoleLayoutActingPrincipalObserver::class);

    $this->withSession(consoleLayoutSession($actor, ConsoleRole::Admin))
        ->get(route('dashboard'))
        ->assertOk();

    expect($observation)->not->toBeEmpty()
        ->and($observation['identifier'] ?? null)->toBe($actor->getAuthIdentifier())
        ->and($observation['delegated'] ?? null)->toBeTrue()
        ->and($observation['same_instance'] ?? null)->toBeTrue();
});

test('delegated principal and chrome outrank a co-resident local admin without fallback', function (): void {
    $localAdmin = consoleLayoutUser(name: 'Local Admin Decoy', email: 'local-decoy@example.test', isAdmin: true);
    $actor = consoleLayoutActor(ConsoleRole::Admin);

    $response = $this->actingAs($localAdmin)
        ->withSession(consoleLayoutSession(
            $actor,
            ConsoleRole::Member,
            displayName: 'Delegated Member',
        ))
        ->get(route('dashboard'))
        ->assertOk();

    $response
        ->assertSeeHtml('data-bfc-console-role="member"')
        ->assertSee('Delegated Member')
        ->assertDontSee('Local Admin Decoy')
        ->assertDontSee('local-decoy@example.test');

    assertTestMarker($response, 'sidebar-invitations', present: false);
});

test('hostile delegated display values stay escaped in Console and Sink chrome', function (): void {
    $hostileName = '<img src=x onerror=alert(1)>" onmouseover="alert(2)';
    $hostileAgency = '</span><script>alert(3)</script>';
    config(['built-for-cloud.console.issuer' => 'https://a<b"c.test/']);

    $actor = consoleLayoutActor(ConsoleRole::Admin);
    $html = (string) $this->withSession(consoleLayoutSession(
        $actor,
        ConsoleRole::Admin,
        displayName: $hostileName,
        agency: $hostileAgency,
    ))->get(route('dashboard'))->assertOk()->getContent();

    $escapedControl = Blade::render('<span title="{{ $value }}">{{ $value }}</span>', ['value' => $hostileName]);
    $rawDecoy = Blade::render('<span title="{!! $value !!}">{!! $value !!}</span>', ['value' => $hostileName]);

    expect($html)->toContain(e($hostileName))
        ->toContain(e($hostileAgency))
        ->toContain(e('a<b"c.test'))
        ->not->toContain('<img')
        ->not->toContain('<script>alert(3)')
        ->not->toContain('" onmouseover="')
        ->and($escapedControl)->not->toContain('<img')
        ->and($rawDecoy)->toContain('<img');
});

test('a capped delegated session receives the exact structured response on a real Sink Livewire update', function (): void {
    ensureConsoleLayoutMessagesTable();
    $actor = consoleLayoutActor(ConsoleRole::Admin);

    $page = $this->withSession(consoleLayoutSession($actor, ConsoleRole::Admin))
        ->get(route('sink.inbox'))
        ->assertOk();
    $snapshot = Utils::extractAttributeDataFromHtml($page->getContent(), 'wire:snapshot');

    Auth::forgetGuards();

    $this->withSession(consoleLayoutSession(
        $actor,
        ConsoleRole::Admin,
        issuedAt: CarbonImmutable::now()->subMinutes(121)->getTimestamp(),
    ));

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
        'return_to' => '/inbox?app=console-test',
    ], ['X-Livewire' => 'true'])
        ->assertUnauthorized()
        ->assertHeader('BFC-Console-Reentry', '1')
        ->assertExactJson([
            'version' => 1,
            'error' => 'console_reentry_required',
            'reason' => 'assertion_age_cap',
            'reentry_url' => 'https://scalpels.test/console/enter',
            'return_to' => '/inbox?app=console-test',
        ]);
});

test('a refused or capped delegated full-page GET is terminal with no fallback to a co-resident local user', function (): void {
    $localAdmin = consoleLayoutUser(name: 'Local Admin Fallback Decoy', email: 'local-fallback-decoy@example.test', isAdmin: true);
    $actor = consoleLayoutActor(ConsoleRole::Admin);

    $this->actingAs($localAdmin)
        ->withSession(consoleLayoutSession(
            $actor,
            ConsoleRole::Admin,
            issuedAt: CarbonImmutable::now()->subMinutes(121)->getTimestamp(),
        ))
        ->get(route('dashboard'))
        ->assertUnauthorized()
        ->assertHeader('BFC-Console-Reentry', '1')
        ->assertExactJson([
            'version' => 1,
            'error' => 'console_reentry_required',
            'reason' => 'assertion_age_cap',
            'reentry_url' => 'https://scalpels.test/console/enter',
            'return_to' => '/dashboard',
        ]);
});

test('a Console-disabled production boot mounts neither delegated door nor chrome asset', function (): void {
    $process = new Process(
        [PHP_BINARY, 'artisan', 'route:list', '--json', '--path=bfc/console'],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'BUILT_FOR_CLOUD_CONSOLE_ENABLED' => 'false',
        ],
    );
    $process->mustRun();

    $uris = collect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))
        ->pluck('uri');

    expect($uris)->not->toContain('bfc/console/enter')
        ->not->toContain('bfc/console/chrome.js')
        ->toContain('bfc/console/vitals');
});

function consoleLayoutUser(string $name, string $email, bool $isAdmin): User
{
    $user = User::factory()->create([
        'name' => $name,
        'email' => $email,
    ]);

    if ($isAdmin) {
        $user->forceFill(['is_admin' => true])->save();
    }

    return $user;
}

function consoleLayoutActor(ConsoleRole $rowRole): DelegatedActor
{
    $issuer = 'https://scalpels.test';
    $subject = 'layout_operator_'.Str::ulid();

    return DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash($issuer, $subject),
        'issuer' => $issuer,
        'subject' => $subject,
        'last_handoff_display_name' => 'Actor row decoy',
        'last_handoff_on_behalf_of' => null,
        'last_handoff_role' => $rowRole,
        'deactivated_at' => null,
    ]);
}

/**
 * @return array<string, mixed>
 */
function consoleLayoutSession(
    DelegatedActor $actor,
    ConsoleRole $role,
    string $displayName = 'Delegated Operator',
    ?string $agency = null,
    ?int $issuedAt = null,
): array {
    $guard = Auth::guard(ConsoleGuardConfiguration::GUARD);

    expect($guard)->toBeInstanceOf(ConsoleGuard::class);

    /** @var ConsoleGuard $guard */
    return [
        $guard->getName() => $actor->getAuthIdentifier(),
        ConsoleSession::ASSERTION_ISSUED_AT => $issuedAt ?? CarbonImmutable::now()->getTimestamp(),
        ConsoleSession::DISPLAY_NAME => $displayName,
        ConsoleSession::ROLE => $role->value,
        ConsoleSession::ON_BEHALF_OF => $agency,
    ];
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
