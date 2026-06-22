<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkClient\Commands;

use ArtisanBuild\SinkContracts\Envelope;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory;
use Throwable;

final class UpdateCommand extends Command
{
    protected $signature = 'sink:update';

    protected $description = 'Check whether this client is compatible with the configured Sink server.';

    public function __construct(private readonly Factory $http)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $url = config('sink-client.url');

        if (blank($url)) {
            $this->error('SINK_URL is not configured. Set SINK_URL before running sink:update.');

            return self::FAILURE;
        }

        try {
            $response = $this->http
                ->withToken((string) config('sink-client.token'))
                ->timeout(max(0.1, (float) config('sink-client.timeout')))
                ->get($this->capabilitiesUrl((string) $url));
        } catch (Throwable $e) {
            $this->warn('Could not reach your Sink server capabilities endpoint: '.$e->getMessage());
            $this->line('If your Sink server does not expose /capabilities yet, upgrade it when that endpoint ships.');

            return self::SUCCESS;
        }

        if (! $response->successful()) {
            $this->warn('Could not fetch Sink server capabilities. HTTP '.$response->status().'.');
            $this->line('If your Sink server does not expose /capabilities yet, upgrade it when that endpoint ships.');

            return self::SUCCESS;
        }

        $capabilities = $response->json('envelope');

        if (! is_array($capabilities) || ! isset($capabilities['min_major'], $capabilities['max_major']) || ! is_int($capabilities['min_major']) || ! is_int($capabilities['max_major'])) {
            $this->warn('Sink server returned invalid capabilities JSON.');

            return self::SUCCESS;
        }

        $current = Envelope::VERSION;

        if ($current > $capabilities['max_major']) {
            $this->error("Your apps are ahead of your Sink server (it supports up to v{$capabilities['max_major']}; you have v{$current}). Update your Sink app first, then re-run sink:update.");

            return self::FAILURE;
        }

        if ($current < $capabilities['min_major']) {
            $this->warn("Your Sink server expects envelope v{$capabilities['min_major']} or newer; you have v{$current}. Update your Sink clients.");

            return self::SUCCESS;
        }

        $this->info("Your Sink server understands envelope v{$current}. You're good.");

        return self::SUCCESS;
    }

    private function capabilitiesUrl(string $url): string
    {
        $url = rtrim($url, '/');

        if (str_ends_with($url, '/ingest')) {
            $url = substr($url, 0, -strlen('/ingest'));
        }

        return $url.'/capabilities';
    }
}
