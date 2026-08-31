<?php

declare(strict_types=1);

use App\Models\User;
use ArtisanBuild\BuiltForCloud\Audit\AppActionActor;
use ArtisanBuild\BuiltForCloud\Audit\AppActionEvent;
use ArtisanBuild\BuiltForCloud\Audit\AppActionReason;
use ArtisanBuild\BuiltForCloud\Audit\AppActionRecorder;
use ArtisanBuild\BuiltForCloud\Audit\ConsoleAction;
use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Support\Facades\DB;

test('message and audit writes share transaction boundaries', function (): void {
    expect((new Message)->getConnection())->toBe(DB::connection());

    $actor = AppActionActor::localUser(User::factory()->create());

    try {
        DB::transaction(function () use ($actor): void {
            createTransactionTestMessage('rolled-back-message');
            app(AppActionRecorder::class)->record(
                action: ConsoleAction::ConsoleEntered,
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
        ->and(AppActionEvent::query()->count())->toBe(0);

    DB::transaction(function () use ($actor): void {
        createTransactionTestMessage('committed-message');
        app(AppActionRecorder::class)->record(
            action: ConsoleAction::ConsoleEntered,
            actor: $actor,
            reason: AppActionReason::Requested,
            naturalKey: 'committed-message',
        );
    });

    expect(Message::query()->count())->toBe(1)
        ->and(AppActionEvent::query()->count())->toBe(1);
});

function createTransactionTestMessage(string $idempotencyKey): void
{
    Message::query()->create([
        'idempotency_key' => $idempotencyKey,
        'app' => 'transaction-test',
        'received_at' => now(),
        'size_bytes' => 1,
        'attachment_count' => 0,
        'link_count' => 0,
        'truncation' => 'none',
        'raw_object_key' => "raw/{$idempotencyKey}.eml",
    ]);
}
