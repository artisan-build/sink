<?php

declare(strict_types=1);

use ArtisanBuild\SinkServer\Actions\DeleteMessage;
use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageBlobCleanupIntent;
use ArtisanBuild\SinkServer\SinkServerServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

test('the default Cloud provisioning template preserves shared destructive transactions', function (): void {
    $resourcePlan = file_get_contents(base_path('.claude/skills/provisioning-sink-on-cloud/reference/resource-plan.md'));

    expect($resourcePlan)->toBeString();

    $loopStart = strpos($resourcePlan, 'for kv in \\');
    $loopEnd = $loopStart === false ? false : strpos($resourcePlan, '; do', $loopStart);

    expect($loopStart)->not->toBeFalse()
        ->and($loopEnd)->not->toBeFalse();

    $defaultVariables = substr($resourcePlan, $loopStart, $loopEnd - $loopStart);
    preg_match_all("/'([A-Z0-9_]+)=/", $defaultVariables, $matches);

    expect($matches[1])->not->toContain(
        'SINK_DB_HOST',
        'SINK_DB_PORT',
        'SINK_DB_DATABASE',
        'SINK_DB_USERNAME',
        'SINK_DB_PASSWORD',
    );

    config()->set('sink-server.database', [
        'connection' => 'sink',
        'host' => null,
        'port' => null,
        'database' => null,
        'username' => null,
        'password' => null,
    ]);

    (new SinkServerServiceProvider(app()))->register();

    $message = Message::query()->create([
        'idempotency_key' => 'provisioned-shared',
        'app' => 'provisioning-test',
        'received_at' => now(),
        'size_bytes' => 3,
        'attachment_count' => 0,
        'link_count' => 0,
        'truncation' => 'none',
        'raw_object_key' => 'raw/provisioned-shared.eml',
    ]);
    Storage::fake((string) config('sink-server.disk'));
    Storage::disk((string) config('sink-server.disk'))->put($message->raw_object_key, 'raw');

    expect((new Message)->getConnection())->toBe(DB::connection())
        ->and((new Message)->getConnection()->getPdo())->toBe(DB::connection()->getPdo())
        ->and(resolve(DeleteMessage::class)($message))->toBe(1)
        ->and(MessageBlobCleanupIntent::query()->count())->toBe(0);

    Storage::disk((string) config('sink-server.disk'))->assertMissing($message->raw_object_key);
});
