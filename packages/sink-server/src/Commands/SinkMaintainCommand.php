<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class SinkMaintainCommand extends Command
{
    protected $signature = 'sink:maintain';

    protected $description = 'Run Sink retention pruning.';

    public function handle(): int
    {
        return Artisan::call('sink:prune');
    }
}
