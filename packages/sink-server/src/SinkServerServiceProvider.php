<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer;

use ArtisanBuild\SinkServer\Commands\SinkMaintainCommand;
use ArtisanBuild\SinkServer\Commands\SinkPruneCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SinkServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sink-server.php', SinkServer::CONFIG_KEY);

        $this->registerSinkConnection();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/sink-server.php' => config_path('sink-server.php'),
        ], 'sink-server-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::prefix((string) config('sink-server.route_prefix', ''))
            ->group(__DIR__.'/../routes/sink-server.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SinkMaintainCommand::class,
                SinkPruneCommand::class,
            ]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('sink:maintain')->hourly();
            });
        }
    }

    private function registerSinkConnection(): void
    {
        $sinkDatabase = config('sink-server.database.database');
        $sinkHost = config('sink-server.database.host');
        $sinkUsername = config('sink-server.database.username');

        if (blank($sinkDatabase) && blank($sinkHost) && blank($sinkUsername)) {
            config(['database.connections.sink' => config('database.connections.'.config('database.default'))]);

            return;
        }

        config(['database.connections.sink' => [
            'driver' => 'pgsql',
            'host' => config('sink-server.database.host'),
            'port' => config('sink-server.database.port'),
            'database' => config('sink-server.database.database'),
            'username' => config('sink-server.database.username'),
            'password' => config('sink-server.database.password'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'timezone' => 'UTC',
        ]]);
    }
}
