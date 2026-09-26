<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Audit\AppActionEvent;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $connection = (string) config('sink-server.database.connection');

    if (! Schema::connection($connection)->hasTable('messages')) {
        Artisan::call('migrate', [
            '--database' => $connection,
            '--path' => 'packages/sink-server/database/migrations',
            '--realpath' => true,
        ]);
    }

    Storage::fake((string) config('sink-server.disk'));
});

test('the package role policy defines Sink product and membership boundaries', function (
    UserRole|string $role,
    bool $canUseProduct,
    bool $canManageMembers,
    bool $canManageAdmins,
    bool $canInitiateTransition,
): void {
    expect(RolePolicy::canUseProduct($role))->toBe($canUseProduct)
        ->and(RolePolicy::canManageMembers($role))->toBe($canManageMembers)
        ->and(RolePolicy::canManageAdmins($role))->toBe($canManageAdmins)
        ->and(RolePolicy::canManage($role, UserRole::Member))->toBe($canManageMembers)
        ->and(RolePolicy::canManage($role, UserRole::Admin))->toBe($canManageAdmins)
        ->and(RolePolicy::canManage($role, UserRole::Owner))->toBeFalse()
        ->and(RolePolicy::canInitiateModeTransition($role))->toBe($canInitiateTransition);
})->with([
    'owner manages members, admins, and transitions' => [UserRole::Owner, true, true, true, true],
    'admin manages members only' => [UserRole::Admin, true, true, false, false],
    'member cannot manage membership' => [UserRole::Member, true, false, false, false],
    'unknown role is denied' => ['unknown-role', false, false, false, false],
]);

test('every package role can inspect and explicitly delete or purge Sink messages', function (UserRole $role): void {
    $user = authorizationUser($role);
    ['message' => $message, 'attachment' => $attachment, 'raw' => $raw] = authorizationMessage(
        app: 'authorization-'.$role->value,
        subject: 'Core access for '.$role->value,
    );
    ['message' => $otherMessage] = authorizationMessage(
        app: 'other-'.$role->value,
        subject: 'Outside attachment scope',
    );

    $this->post(route('bfc.login.store'), [
        'email' => $user->email,
        'password' => 'test-created-password',
    ])->assertRedirect(route('bfc.dashboard', absolute: false));

    $inbox = $this->get(route('sink.inbox'))->assertOk()->assertSee($message->subject);
    assertTestMarker($inbox, 'inbox-purge');

    $detail = $this->get(route('sink.message', $message))
        ->assertOk()
        ->assertSee($message->subject)
        ->assertSee('X-Authorization-Test')
        ->assertSee('https://example.test/authorization')
        ->assertSee($attachment->filename);
    assertTestMarker($detail, 'message-delete');

    $this->get(route('sink.message.body', $message))
        ->assertOk()
        ->assertSee('Role body '.$role->value, false);
    $this->get(route('sink.message.raw', $message))->assertOk()->assertContent($raw);

    $download = $this->get(route('sink.message.attachment', [$message, $attachment]))->assertOk();
    expect($download->streamedContent())->toBe('attachment for '.$role->value);

    $this->get(route('sink.message.attachment', [$otherMessage, $attachment]))->assertNotFound();
    $this->delete(route('sink.inbox.purge'))->assertUnprocessable();

    $this->delete(route('sink.message.destroy', $message))->assertRedirect(route('sink.inbox'));
    expect(Message::query()->whereKey($message->getKey())->exists())->toBeFalse();

    ['message' => $purgeTarget] = authorizationMessage(
        app: 'purge-'.$role->value,
        subject: 'Scoped purge target',
    );
    ['message' => $purgeSurvivor] = authorizationMessage(
        app: 'survivor-'.$role->value,
        subject: 'Scoped purge survivor',
    );

    $this->delete(route('sink.inbox.purge'), ['app' => 'purge-'.$role->value])
        ->assertRedirect(route('sink.inbox'));

    expect(Message::query()->whereKey($purgeTarget->getKey())->exists())->toBeFalse()
        ->and(Message::query()->whereKey($purgeSurvivor->getKey())->exists())->toBeTrue()
        ->and(AppActionEvent::query()->where('action', 'message_deleted')->sole()->getAttributes())->toMatchArray([
            'actor_type' => 'local_user',
            'actor_ref' => (string) $user->getAuthIdentifier(),
        ])->and(AppActionEvent::query()->where('action', 'messages_purged')->sole()->getAttributes())->toMatchArray([
            'actor_type' => 'local_user',
            'actor_ref' => (string) $user->getAuthIdentifier(),
        ]);
})->with([
    'owner' => [UserRole::Owner],
    'admin' => [UserRole::Admin],
    'member' => [UserRole::Member],
]);

test('bfc auth denies an unknown package role across Sink core routes', function (): void {
    $user = authorizationUser('unknown-role');
    ['message' => $message, 'attachment' => $attachment] = authorizationMessage(
        app: 'unknown-role',
        subject: 'Unknown role must not access this',
    );

    $requests = [
        ['getJson', route('sink.inbox'), []],
        ['getJson', route('sink.message', $message), []],
        ['getJson', route('sink.message.body', $message), []],
        ['getJson', route('sink.message.raw', $message), []],
        ['getJson', route('sink.message.attachment', [$message, $attachment]), []],
        ['deleteJson', route('sink.message.destroy', $message), []],
        ['deleteJson', route('sink.inbox.purge'), ['app' => 'unknown-role']],
    ];

    foreach ($requests as [$method, $uri, $data]) {
        $this->actingAs($user)
            ->withSession([
                StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
                'authorization-residue' => 'present',
            ])
            ->{$method}($uri, $data)
            ->assertForbidden()
            ->assertSessionMissing('authorization-residue');
    }

    expect(Message::query()->whereKey($message->getKey())->exists())->toBeTrue()
        ->and(AppActionEvent::query()->count())->toBe(0);
});

test('bfc auth denies an offboarded package user and invalidates the surviving session', function (): void {
    $user = authorizationUser(UserRole::Owner);

    $this->post(route('bfc.login.store'), [
        'email' => $user->email,
        'password' => 'test-created-password',
    ])->assertRedirect(route('bfc.dashboard', absolute: false));
    $this->get(route('sink.inbox'))->assertOk();

    OffboardedSubject::query()->create([
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => $user->email,
        'user_id' => (string) $user->getAuthIdentifier(),
        'offboarded_at' => now(),
    ]);

    $this->withSession(['authorization-residue' => 'present'])
        ->get(route('sink.inbox'))
        ->assertForbidden()
        ->assertSessionMissing('authorization-residue');
});

function authorizationUser(UserRole|string $role): User
{
    $user = User::query()->create([
        'name' => 'Authorization '.($role instanceof UserRole ? $role->value : $role),
        'email' => 'authorization-'.($role instanceof UserRole ? $role->value : $role).'-'.Str::ulid().'@example.test',
        'password' => Hash::make('test-created-password'),
    ]);
    $user->forceFill([
        'role' => $role instanceof UserRole ? $role->value : $role,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    return $user;
}

/**
 * @return array{message: Message, attachment: MessageAttachment, raw: string}
 */
function authorizationMessage(string $app, string $subject): array
{
    $role = Str::afterLast($app, '-');
    $raw = implode("\r\n", [
        'From: sender@example.test',
        'To: recipient@example.test',
        'Subject: '.$subject,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        '',
        '<html><body><p>Role body '.$role.'</p></body></html>',
    ]);
    $rawObjectKey = 'raw/authorization/'.Str::ulid().'.eml';
    $message = Message::query()->create([
        'idempotency_key' => (string) Str::ulid(),
        'app' => $app,
        'stream' => null,
        'subject' => $subject,
        'from_address' => 'sender@example.test',
        'from_name' => 'Authorization Sender',
        'message_id' => '<'.Str::ulid().'@example.test>',
        'sent_at' => now()->subMinute(),
        'received_at' => now(),
        'size_bytes' => strlen($raw),
        'attachment_count' => 1,
        'link_count' => 1,
        'truncation' => 'none',
        'raw_object_key' => $rawObjectKey,
        'parsed_at' => now(),
    ]);
    $message->headers()->create(['name' => 'X-Authorization-Test', 'value' => $role]);
    $message->links()->create(['url' => 'https://example.test/authorization', 'label' => 'Authorization']);
    $attachment = $message->attachments()->create([
        'filename' => 'authorization-'.$role.'.txt',
        'mime' => 'text/plain',
        'size_bytes' => strlen('attachment for '.$role),
        'object_key' => 'attachments/authorization/'.Str::ulid().'.txt',
    ]);

    Storage::disk((string) config('sink-server.disk'))->put($rawObjectKey, $raw);
    Storage::disk((string) config('sink-server.disk'))->put($attachment->object_key, 'attachment for '.$role);

    return compact('message', 'attachment', 'raw');
}
