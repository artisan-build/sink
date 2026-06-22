<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkClient;

use ArtisanBuild\SinkContracts\Envelope;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Throwable;

final class ContractsVersionNudge
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly LoggerInterface $logger,
    ) {}

    public function check(): void
    {
        try {
            $path = storage_path('framework/sink/contracts-major');
            $current = (string) Envelope::VERSION;
            $previous = $this->readPrevious($path);

            if ($previous !== $current) {
                $this->files->ensureDirectoryExists(dirname($path));
                $this->files->put($path, $current);

                if ($previous !== null) {
                    $this->logger->warning('Sink contracts major changed; run `php artisan sink:update` to verify server compatibility.');
                }
            }
        } catch (Throwable) {
            return;
        }
    }

    private function readPrevious(string $path): ?string
    {
        try {
            if (! $this->files->exists($path)) {
                return null;
            }

            return trim($this->files->get($path));
        } catch (FileNotFoundException) {
            return null;
        }
    }
}
