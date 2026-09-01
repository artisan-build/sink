<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Commands;

use ArtisanBuild\SinkServer\Actions\CleanupMessageBlobs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class SinkMaintainCommand extends Command
{
    protected $signature = 'sink:maintain';

    protected $description = 'Run Sink blob cleanup and retention pruning.';

    public function handle(CleanupMessageBlobs $cleanupMessageBlobs): int
    {
        $cleanupMessageBlobs();

        return Artisan::call('sink:prune');
    }
}
