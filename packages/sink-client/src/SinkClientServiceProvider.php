<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkClient;

use ArtisanBuild\SinkClient\Commands\InstallCommand;
use ArtisanBuild\SinkClient\Commands\UpdateCommand;
use ArtisanBuild\SinkClient\Exceptions\SinkNotConfigured;
use ArtisanBuild\SinkClient\Exceptions\SinkProductionFuse;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

final class SinkClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sink-client.php', SinkClient::CONFIG_KEY);

        if (config('mail.mailers.sink') === null) {
            config(['mail.mailers.sink' => ['transport' => 'sink']]);
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/sink-client.php' => config_path('sink-client.php'),
        ], 'sink-client-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                UpdateCommand::class,
            ]);
        }

        $this->app->make(ContractsVersionNudge::class)->check();

        Mail::extend('sink', function (): SinkTransport {
            $this->guardConfiguration();

            return new SinkTransport(
                url: (string) config('sink-client.url'),
                token: (string) config('sink-client.token'),
                stream: blank(config('sink-client.stream')) ? null : (string) config('sink-client.stream'),
                retryAttempts: max(1, (int) config('sink-client.retry_attempts')),
                retryBaseMs: max(1, (int) config('sink-client.retry_base_ms')),
                timeout: max(0.1, (float) config('sink-client.timeout')),
                maxMessageBytes: max(0, (int) config('sink-client.max_message_bytes')),
                http: $this->app->make(Factory::class),
            );
        });
    }

    private function guardConfiguration(): void
    {
        $missing = [];

        if (blank(config('sink-client.url'))) {
            $missing[] = 'SINK_URL';
        }

        if (blank(config('sink-client.token'))) {
            $missing[] = 'SINK_TOKEN';
        }

        if ($missing !== []) {
            throw SinkNotConfigured::missing($missing);
        }

        if ($this->app->environment('production') && ! (bool) config('sink-client.allow_production')) {
            throw SinkProductionFuse::blocked();
        }
    }
}
