<?php

declare(strict_types=1);

use App\Models\User;
use ArtisanBuild\BuiltForCloud\Audit\AppActionActor;
use ArtisanBuild\BuiltForCloud\Audit\AppActionEvent;
use ArtisanBuild\BuiltForCloud\Audit\AppActionOutboxEntry;
use ArtisanBuild\BuiltForCloud\Audit\AppActionReason;
use ArtisanBuild\BuiltForCloud\Audit\AppActionRecorder;
use ArtisanBuild\BuiltForCloud\Console\ConsoleGuard;
use ArtisanBuild\BuiltForCloud\Console\ConsoleGuardConfiguration;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\ConsoleSession;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\SinkServer\Audit\SinkAction;
use ArtisanBuild\SinkServer\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake((string) config('sink-server.disk'));
});

test('Sink declares the exact bounded action vocabulary', function (): void {
    expect(enum_exists(SinkAction::class))->toBeTrue()
        ->and(array_column(SinkAction::cases(), 'value'))->toBe([
            'message_deleted',
            'messages_purged',
        ]);
});

test('the real UI delete route records exactly one local-user event and ledger row', function (): void {
    $admin = auditAdmin();
    $message = createAuditMessage('local-delete', subject: 'Delete content must not enter audit');

    $this->actingAs($admin)
        ->delete(route('sink.message.destroy', $message))
        ->assertRedirect(route('sink.inbox'));

    $event = AppActionEvent::query()->sole();
    $ledger = AppActionOutboxEntry::query()->sole();

    expect($event->getAttributes())->toMatchArray([
        'action' => 'message_deleted',
        'action_vocabulary' => SinkAction::class,
        'reason' => AppActionReason::Requested->value,
        'actor_type' => 'local_user',
        'actor_ref' => (string) $admin->getAuthIdentifier(),
        'on_behalf_of' => null,
    ])->and($ledger->event_id)->toBe($event->id)
        ->and($ledger->dedup_key)->toBe(AppActionRecorder::dedupKeyFor(
            SinkAction::MessageDeleted,
            (string) $message->getKey(),
        ))
        ->and(json_encode([$event->getAttributes(), $ledger->getAttributes()], JSON_THROW_ON_ERROR))
        ->not->toContain('Delete content must not enter audit')
        ->not->toContain('local-delete');
});

test('the real UI purge route records one delegated event for the whole purge', function (): void {
    $actor = auditDelegatedActor();
    $messages = [
        createAuditMessage('delegated-purge-one', appName: 'audit-purge', subject: 'First private subject'),
        createAuditMessage('delegated-purge-two', appName: 'audit-purge', subject: 'Second private subject'),
    ];

    $this->withSession(auditDelegatedSession($actor, 'Audit Agency'))
        ->delete(route('sink.inbox.purge'), ['app' => 'audit-purge'])
        ->assertRedirect(route('sink.inbox'));

    $event = AppActionEvent::query()->sole();
    $ledger = AppActionOutboxEntry::query()->sole();

    expect(Message::query()->whereKey($messages[0]->getKey())->exists())->toBeFalse()
        ->and(Message::query()->whereKey($messages[1]->getKey())->exists())->toBeFalse()
        ->and($event->getAttributes())->toMatchArray([
            'action' => 'messages_purged',
            'action_vocabulary' => SinkAction::class,
            'reason' => AppActionReason::Requested->value,
            'actor_type' => 'delegated_actor',
            'actor_ref' => $actor->getAuthIdentifier(),
            'on_behalf_of' => 'Audit Agency',
        ])->and($ledger->event_id)->toBe($event->id)
        ->and($ledger->dedup_key)->toBe(AppActionRecorder::dedupKeyFor(
            SinkAction::MessagesPurged,
            $event->id,
        ));

    $rows = json_encode([$event->getAttributes(), $ledger->getAttributes()], JSON_THROW_ON_ERROR);

    expect($rows)->not->toContain('audit-purge')
        ->not->toContain('First private subject')
        ->not->toContain('Second private subject')
        ->not->toContain('delegated-purge-one')
        ->not->toContain('delegated-purge-two');
});

test('UI purge refusal and destructive no-ops emit no successful action event', function (): void {
    $admin = auditAdmin();

    $this->actingAs($admin)
        ->delete(route('sink.inbox.purge'))
        ->assertUnprocessable();

    $this->actingAs($admin)
        ->delete(route('sink.inbox.purge'), ['app' => 'no-matching-messages'])
        ->assertRedirect(route('sink.inbox'));

    $this->actingAs($admin)
        ->delete(route('sink.message.destroy', PHP_INT_MAX))
        ->assertNotFound();

    expect(AppActionEvent::query()->count())->toBe(0)
        ->and(AppActionOutboxEntry::query()->count())->toBe(0);
});

test('a forced recorder failure rolls back the UI domain mutation on the shared connection', function (): void {
    expect(enum_exists(SinkAction::class))->toBeTrue();

    $admin = auditAdmin();
    $message = createAuditMessage('recorder-failure', rawObjectKey: 'raw/recorder-failure.eml');

    DB::transaction(function () use ($admin, $message): void {
        resolve(AppActionRecorder::class)->record(
            action: SinkAction::MessageDeleted,
            actor: AppActionActor::localUser($admin),
            reason: AppActionReason::Requested,
            naturalKey: (string) $message->getKey(),
        );
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($admin)->delete(route('sink.message.destroy', $message)))
        ->toThrow(QueryException::class);

    expect((new Message)->getConnection())->toBe(DB::connection())
        ->and((new Message)->getConnection()->getPdo())->toBe(DB::connection()->getPdo())
        ->and(Message::query()->whereKey($message->getKey())->exists())->toBeTrue()
        ->and(AppActionEvent::query()->count())->toBe(1)
        ->and(AppActionOutboxEntry::query()->count())->toBe(1);

    Storage::disk((string) config('sink-server.disk'))->assertMissing('raw/recorder-failure.eml');
});

test('message and audit writes share rollback and commit boundaries', function (): void {
    expect(enum_exists(SinkAction::class))->toBeTrue()
        ->and((new Message)->getConnection())->toBe(DB::connection())
        ->and((new Message)->getConnection()->getPdo())->toBe(DB::connection()->getPdo());

    $actor = AppActionActor::localUser(User::factory()->create());

    try {
        DB::transaction(function () use ($actor): void {
            createAuditMessage('rolled-back-message');
            resolve(AppActionRecorder::class)->record(
                action: SinkAction::MessageDeleted,
                actor: $actor,
                reason: AppActionReason::Requested,
                naturalKey: 'rolled-back-message',
            );

            throw new RuntimeException('Force the transaction to roll back.');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Force the transaction to roll back.');
    }

    expect(Message::query()->count())->toBe(0)
        ->and(AppActionEvent::query()->count())->toBe(0)
        ->and(AppActionOutboxEntry::query()->count())->toBe(0);

    DB::transaction(function () use ($actor): void {
        createAuditMessage('committed-message');
        resolve(AppActionRecorder::class)->record(
            action: SinkAction::MessageDeleted,
            actor: $actor,
            reason: AppActionReason::Requested,
            naturalKey: 'committed-message',
        );
    });

    expect(Message::query()->count())->toBe(1)
        ->and(AppActionEvent::query()->count())->toBe(1)
        ->and(AppActionOutboxEntry::query()->count())->toBe(1);
});

function auditAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

function auditDelegatedActor(): DelegatedActor
{
    $issuer = 'https://scalpels.audit.test';
    $subject = 'audit_operator_'.Str::ulid();

    return DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash($issuer, $subject),
        'issuer' => $issuer,
        'subject' => $subject,
        'last_handoff_display_name' => 'Audit Operator',
        'last_handoff_on_behalf_of' => 'Stale row agency',
        'last_handoff_role' => ConsoleRole::Member,
        'deactivated_at' => null,
    ]);
}

/**
 * @return array<string, mixed>
 */
function auditDelegatedSession(DelegatedActor $actor, string $onBehalfOf): array
{
    $guard = Auth::guard(ConsoleGuardConfiguration::GUARD);

    expect($guard)->toBeInstanceOf(ConsoleGuard::class);

    /** @var ConsoleGuard $guard */
    return [
        $guard->getName() => $actor->getAuthIdentifier(),
        ConsoleSession::ASSERTION_ISSUED_AT => CarbonImmutable::now()->getTimestamp(),
        ConsoleSession::DISPLAY_NAME => 'Audit Operator',
        ConsoleSession::ROLE => ConsoleRole::Admin->value,
        ConsoleSession::ON_BEHALF_OF => $onBehalfOf,
    ];
}

function createAuditMessage(
    string $idempotencyKey,
    string $appName = 'audit-test',
    string $subject = 'Audit transaction message',
    ?string $rawObjectKey = null,
): Message {
    $rawObjectKey ??= "raw/{$idempotencyKey}.eml";

    $message = Message::query()->create([
        'idempotency_key' => $idempotencyKey,
        'app' => $appName,
        'subject' => $subject,
        'received_at' => now(),
        'size_bytes' => 1,
        'attachment_count' => 0,
        'link_count' => 0,
        'truncation' => 'none',
        'raw_object_key' => $rawObjectKey,
    ]);

    Storage::disk((string) config('sink-server.disk'))->put($rawObjectKey, 'private message body');

    return $message;
}
