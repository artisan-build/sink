<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Tests;

use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use ArtisanBuild\SinkServer\SinkServerServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['sink'];

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BuiltForCloudServiceProvider::class,
            McpServiceProvider::class,
            LivewireServiceProvider::class,
            SinkServerServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sink');
        $app['config']->set('database.connections.sink', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('sink-server.database', [
            'connection' => 'sink',
            'host' => null,
            'port' => null,
            'database' => null,
            'username' => null,
            'password' => null,
        ]);
        $app['config']->set('built-for-cloud.fallback_token', 'test-token');
    }
}
