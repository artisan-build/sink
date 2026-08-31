<?php

declare(strict_types=1);

use ArtisanBuild\SinkServer\Models\Message;
use ArtisanBuild\SinkServer\Models\MessageAttachment;
use ArtisanBuild\SinkServer\Models\MessageHeader;
use ArtisanBuild\SinkServer\Models\MessageLink;
use ArtisanBuild\SinkServer\Models\MessageRecipient;
use ArtisanBuild\SinkServer\SinkServerServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('blank message database configuration uses the default connection instance', function (): void {
    config()->set('database.default', 'application');
    config()->set('database.connections.application', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('database.connections.sink');
    config()->set('sink-server.database', blankSinkDatabaseConfiguration());

    (new SinkServerServiceProvider(app()))->register();

    expect(config('sink-server.database.connection'))->toBe('application')
        ->and(config('database.connections.sink'))->toBeNull();

    foreach (sinkModels() as $model) {
        expect($model->getConnection())->toBe(DB::connection());
    }
});

test('explicit message database configuration keeps a separate connection', function (): void {
    config()->set('database.default', 'application');
    config()->set('database.connections.application', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('sink-server.database', [
        'connection' => 'sink',
        'host' => 'message-database',
        'port' => 5432,
        'database' => 'sink_messages',
        'username' => 'sink',
        'password' => 'secret',
    ]);

    (new SinkServerServiceProvider(app()))->register();

    expect(config('sink-server.database.connection'))->toBe('sink')
        ->and(config('database.connections.sink.driver'))->toBe('pgsql');

    foreach (sinkModels() as $model) {
        expect($model->getConnection())->toBe(DB::connection('sink'))
            ->and($model->getConnection())->not->toBe(DB::connection());
    }
});

test('message migrations use the configured connection', function (): void {
    config()->set('database.connections.message-migrations', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('sink-server.database.connection', 'message-migrations');

    $messages = require __DIR__.'/../database/migrations/2026_06_22_000001_create_messages_table.php';
    $children = require __DIR__.'/../database/migrations/2026_06_22_000002_create_message_children_tables.php';

    $messages->up();
    $children->up();

    expect(Schema::connection('message-migrations')->hasTable('messages'))->toBeTrue()
        ->and(Schema::connection('message-migrations')->hasTable('message_recipients'))->toBeTrue()
        ->and(Schema::connection('message-migrations')->hasTable('message_headers'))->toBeTrue()
        ->and(Schema::connection('message-migrations')->hasTable('message_links'))->toBeTrue()
        ->and(Schema::connection('message-migrations')->hasTable('message_attachments'))->toBeTrue();
});

/**
 * @return array{connection: string, host: null, port: null, database: null, username: null, password: null}
 */
function blankSinkDatabaseConfiguration(): array
{
    return [
        'connection' => 'sink',
        'host' => null,
        'port' => null,
        'database' => null,
        'username' => null,
        'password' => null,
    ];
}

/**
 * @return list<Message|MessageRecipient|MessageHeader|MessageLink|MessageAttachment>
 */
function sinkModels(): array
{
    return [
        new Message,
        new MessageRecipient,
        new MessageHeader,
        new MessageLink,
        new MessageAttachment,
    ];
}
