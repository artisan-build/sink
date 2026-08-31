<?php

use App\Models\User;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Console\ConsoleGuard;
use ArtisanBuild\BuiltForCloud\Console\ConsoleGuardConfiguration;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\ConsoleSession;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Invitation;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\SinkServer\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PR3 proves Sink's app authorization policy with authentic local and shipped
 * bfc-console sessions. It intentionally does not prove production Console
 * route or bfc::layout wiring; those remain the PR4 / §5-bis.2 deliverable.
 */
beforeEach(function (): void {
    expect((bool) config('built-for-cloud.console.enabled'))->toBeTrue()
        ->and(config('auth.guards.'.ConsoleGuardConfiguration::GUARD.'.driver'))
        ->toBe(ConsoleGuardConfiguration::DRIVER)
        ->and(Auth::guard(ConsoleGuardConfiguration::GUARD))->toBeInstanceOf(ConsoleGuard::class);

    Route::middleware([
        StartSession::class,
        'bfc.console',
        'auth:'.ConsoleGuardConfiguration::GUARD,
    ])->get('/_test/authorization/console-probe', function (): array {
        $acting = resolve(ActingPrincipalResolver::class)->resolve();

        return [
            'principal' => $acting->identifier(),
            'delegated' => $acting->delegated,
            'role' => $acting->role?->value,
            'row_role' => $acting->delegatedActor?->last_handoff_role->value,
            'ability' => Gate::allows('administer-sink'),
            'same_resolution' => $acting === resolve(ActingPrincipalResolver::class)->resolve(),
            'local_session_user' => Auth::guard('web')->id(),
        ];
    });

    Route::middleware([
        StartSession::class,
        'bfc.console',
        'auth:'.ConsoleGuardConfiguration::GUARD,
        'can:administer-sink',
    ])->get('/_test/authorization/console-admin', fn (): array => ['authorized' => true]);

    Route::middleware([StartSession::class, 'auth:web'])
        ->get('/_test/authorization/local-probe', function (): array {
            $acting = resolve(ActingPrincipalResolver::class)->resolve();

            return [
                'principal' => $acting->identifier(),
                'delegated' => $acting->delegated,
                'delegated_session_present' => $acting->delegatedSessionPresent(),
                'refused' => $acting->wasRefused(),
                'ability' => Gate::allows('administer-sink'),
            ];
        });

    Route::middleware([StartSession::class, 'auth:web', 'can:administer-sink'])
        ->get('/_test/authorization/local-admin', fn (): array => ['authorized' => true]);
});

test('the ability maps only local Sink admins to administrative standing', function (bool $isAdmin): void {
    $user = authorizationUser($isAdmin);

    $this->actingAs($user);
    $acting = resolve(ActingPrincipalResolver::class)->resolve();

    expect($acting->principal)->toBe($user)
        ->and($acting->delegated)->toBeFalse()
        ->and(Gate::allows('administer-sink'))->toBe($isAdmin);
})->with([
    'positive local admin' => [true],
    'decoy local member is denied' => [false],
]);

test('guests have no administrative standing', function (): void {
    expect(Gate::allows('administer-sink'))->toBeFalse();
});

test('local route enforcement and every admin affordance share the ability', function (bool $isAdmin): void {
    if (! Schema::connection('sink')->hasTable('messages')) {
        Artisan::call('migrate', [
            '--database' => 'sink',
            '--path' => 'packages/sink-server/database/migrations',
            '--realpath' => true,
        ]);
    }

    Storage::fake((string) config('sink-server.disk'));

    $user = authorizationUser($isAdmin);
    $message = authorizationMessage();
    $this->actingAs($user);

    $dashboard = $this->get(route('dashboard'))->assertOk();
    $inbox = $this->get(route('sink.inbox'))->assertOk();
    $detail = $this->get(route('sink.message', $message))->assertOk();

    if ($isAdmin) {
        $dashboard->assertSee('Invitations');
        $inbox->assertSee('Purge filtered scope');
        $detail->assertSee('Delete message');

        $this->get(route('invitations'))->assertOk();
        $this->delete(route('sink.inbox.purge'))->assertUnprocessable();
        $this->delete(route('sink.message.destroy', $message))->assertRedirect(route('sink.inbox'));
    } else {
        $dashboard->assertDontSee('Invitations');
        $inbox->assertDontSee('Purge filtered scope');
        $detail->assertDontSee('Delete message');

        $this->get(route('invitations'))->assertForbidden();
        $this->delete(route('sink.inbox.purge'))->assertForbidden();
        $this->delete(route('sink.message.destroy', $message))->assertForbidden();
    }
})->with([
    'positive local admin' => [true],
    'decoy local member is denied' => [false],
]);

test('the invitations route denies an offboarded local admin and invalidates the surviving session', function (): void {
    $admin = authorizationUser(true);

    $this->actingAs($admin)->withSession(['residue' => 'still-here']);
    $this->get(route('invitations'))->assertOk();

    OffboardedSubject::query()->create([
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => $admin->email,
        'user_id' => (string) $admin->getAuthIdentifier(),
        'offboarded_at' => now(),
    ]);

    $this->get(route('invitations'))->assertForbidden();

    expect(session()->has('residue'))->toBeFalse();
});

test('the shipped console guard admits delegated admins and denies delegated members', function (ConsoleRole $role): void {
    $actor = authorizationDelegatedActor($role);

    $this->withSession(authorizationDelegatedSession($actor, $role));

    $response = $this->getJson('/_test/authorization/console-admin');

    if ($role === ConsoleRole::Admin) {
        $response->assertOk()->assertJsonPath('authorized', true);
    } else {
        $response->assertForbidden();
    }
})->with([
    'positive delegated admin' => [ConsoleRole::Admin],
    'decoy delegated member is denied' => [ConsoleRole::Member],
]);

test('a delegated member outranks a co-resident local admin from one resolved principal', function (): void {
    $localAdmin = authorizationUser(true);
    $actor = authorizationDelegatedActor(ConsoleRole::Admin);

    $this->actingAs($localAdmin)
        ->withSession(authorizationDelegatedSession($actor, ConsoleRole::Member));

    $this->getJson('/_test/authorization/console-probe')
        ->assertOk()
        ->assertJsonPath('principal', $actor->getAuthIdentifier())
        ->assertJsonPath('delegated', true)
        ->assertJsonPath('role', ConsoleRole::Member->value)
        ->assertJsonPath('row_role', ConsoleRole::Admin->value)
        ->assertJsonPath('ability', false)
        ->assertJsonPath('same_resolution', true)
        ->assertJsonPath('local_session_user', $localAdmin->getKey());
});

test('a delegated admin outranks a co-resident local member from one resolved principal', function (): void {
    $localMember = authorizationUser(false);
    $actor = authorizationDelegatedActor(ConsoleRole::Member);

    $this->actingAs($localMember)
        ->withSession(authorizationDelegatedSession($actor, ConsoleRole::Admin));

    $this->getJson('/_test/authorization/console-probe')
        ->assertOk()
        ->assertJsonPath('principal', $actor->getAuthIdentifier())
        ->assertJsonPath('delegated', true)
        ->assertJsonPath('role', ConsoleRole::Admin->value)
        ->assertJsonPath('row_role', ConsoleRole::Member->value)
        ->assertJsonPath('ability', true)
        ->assertJsonPath('same_resolution', true)
        ->assertJsonPath('local_session_user', $localMember->getKey());

    $this->getJson('/_test/authorization/console-admin')->assertOk();
});

test('a present but non-acting delegated session cannot borrow local admin standing', function (): void {
    $localAdmin = authorizationUser(true);
    $actor = authorizationDelegatedActor(ConsoleRole::Admin);

    $this->actingAs($localAdmin)
        ->withSession(authorizationDelegatedSession($actor, ConsoleRole::Admin));

    $this->getJson('/_test/authorization/local-probe')
        ->assertOk()
        ->assertJsonPath('principal', $localAdmin->getKey())
        ->assertJsonPath('delegated', false)
        ->assertJsonPath('delegated_session_present', true)
        ->assertJsonPath('refused', false)
        ->assertJsonPath('ability', false);

    $this->getJson('/_test/authorization/local-admin')->assertForbidden();
});

test('a refused delegated session is terminal and never falls back to a local admin', function (): void {
    $localAdmin = authorizationUser(true);
    $actor = authorizationDelegatedActor(ConsoleRole::Admin);
    $expiredSession = authorizationDelegatedSession(
        $actor,
        ConsoleRole::Admin,
        CarbonImmutable::now()->subMinutes(121)->getTimestamp(),
    );

    $this->actingAs($localAdmin)->withSession($expiredSession);
    $this->getJson('/_test/authorization/local-admin')->assertForbidden();

    $this->actingAs($localAdmin)->withSession($expiredSession);
    $this->getJson('/_test/authorization/local-probe')
        ->assertOk()
        ->assertJsonPath('principal', null)
        ->assertJsonPath('delegated', false)
        ->assertJsonPath('delegated_session_present', true)
        ->assertJsonPath('refused', true)
        ->assertJsonPath('ability', false);
});

test('delegated type-qualified identifiers fit the shipped invitation inviter column', function (): void {
    $actor = authorizationDelegatedActor(ConsoleRole::Admin);
    $identifier = $actor->getAuthIdentifier();

    $invitation = Invitation::invite(
        'delegated-inviter@example.test',
        3600,
        invitedBy: $identifier,
    );

    expect($identifier)->toStartWith(DelegatedActor::IDENTIFIER_PREFIX)
        ->and(Str::length($identifier))->toBeLessThanOrEqual(64)
        ->and($invitation->refresh()->invited_by)->toBe($identifier);
});

function authorizationUser(bool $isAdmin): User
{
    $user = User::factory()->create();

    if ($isAdmin) {
        $user->forceFill(['is_admin' => true])->save();
    }

    return $user;
}

function authorizationDelegatedActor(ConsoleRole $rowRole): DelegatedActor
{
    $issuer = 'https://scalpels.test';
    $subject = 'operator_'.Str::ulid();

    return DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash($issuer, $subject),
        'issuer' => $issuer,
        'subject' => $subject,
        'last_handoff_display_name' => 'Delegated Operator',
        'last_handoff_on_behalf_of' => null,
        'last_handoff_role' => $rowRole,
        'deactivated_at' => null,
    ]);
}

/**
 * The exact state written by ConsoleGuard redemption, read through the real
 * package guard and provider. Direct seeding isolates post-handoff policy; it
 * does not prove assertion verification or the production entry route.
 *
 * @return array<string, mixed>
 */
function authorizationDelegatedSession(
    DelegatedActor $actor,
    ConsoleRole $sessionRole,
    ?int $issuedAt = null,
): array {
    $guard = Auth::guard(ConsoleGuardConfiguration::GUARD);

    expect($guard)->toBeInstanceOf(ConsoleGuard::class);

    /** @var ConsoleGuard $guard */
    return [
        $guard->getName() => $actor->getAuthIdentifier(),
        ConsoleSession::ASSERTION_ISSUED_AT => $issuedAt ?? CarbonImmutable::now()->getTimestamp(),
        ConsoleSession::DISPLAY_NAME => 'Delegated Operator',
        ConsoleSession::ROLE => $sessionRole->value,
        ConsoleSession::ON_BEHALF_OF => null,
    ];
}

function authorizationMessage(): Message
{
    return Message::query()->create([
        'idempotency_key' => (string) Str::ulid(),
        'app' => 'authorization-test',
        'stream' => null,
        'subject' => 'Authorization test message',
        'from_address' => 'sender@example.test',
        'from_name' => 'Sender',
        'message_id' => '<authorization@example.test>',
        'sent_at' => now()->subMinute(),
        'received_at' => now(),
        'size_bytes' => 1,
        'attachment_count' => 0,
        'link_count' => 0,
        'truncation' => 'none',
        'raw_object_key' => 'raw/authorization.eml',
        'parsed_at' => now(),
    ]);
}
