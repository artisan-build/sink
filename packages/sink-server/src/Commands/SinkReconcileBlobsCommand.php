<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Commands;

use ArtisanBuild\SinkServer\Actions\ReconcileMessageBlobs;
use Illuminate\Console\Command;

final class SinkReconcileBlobsCommand extends Command
{
    protected $signature = 'sink:reconcile-blobs
                            {--dry-run : List orphaned blobs without deleting them}';

    protected $description = 'Reclaim unreferenced Sink object-storage blobs.';

    public function handle(ReconcileMessageBlobs $reconcileMessageBlobs): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $reconciled = $reconcileMessageBlobs(
            dryRun: $dryRun,
            orphaned: $dryRun
                ? fn (string $objectKey) => $this->line("Would delete orphaned blob: {$objectKey}")
                : null,
        );

        $this->info($dryRun
            ? "Would reclaim {$reconciled} orphaned Sink blobs."
            : "Reclaimed {$reconciled} orphaned Sink blobs.");

        return self::SUCCESS;
    }
}
