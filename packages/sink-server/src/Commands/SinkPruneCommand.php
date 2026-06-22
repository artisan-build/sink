<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Commands;

use ArtisanBuild\SinkServer\Actions\DeleteMessage;
use ArtisanBuild\SinkServer\Models\Message;
use Illuminate\Console\Command;

final class SinkPruneCommand extends Command
{
    protected $signature = 'sink:prune';

    protected $description = 'Prune expired Sink messages and object-storage blobs.';

    public function handle(): int
    {
        $deleted = 0;
        $days = (int) config('sink-server.retention.days', 7);

        $expired = Message::query()
            ->where('received_at', '<', now()->subDays($days))
            ->orderBy('received_at')
            ->pluck('id');

        foreach ($expired as $messageId) {
            $deleted += app(DeleteMessage::class)((int) $messageId);
        }

        $maxMessages = config('sink-server.retention.max_messages');

        if (is_int($maxMessages) && $maxMessages > 0) {
            $overflow = Message::query()
                ->orderByDesc('received_at')
                ->skip($maxMessages)
                ->take(PHP_INT_MAX)
                ->pluck('id');

            foreach ($overflow as $messageId) {
                $deleted += app(DeleteMessage::class)((int) $messageId);
            }
        }

        $maxTotalBytes = config('sink-server.retention.max_total_bytes');

        if (is_int($maxTotalBytes) && $maxTotalBytes > 0) {
            while ((int) Message::query()->sum('size_bytes') > $maxTotalBytes) {
                $oldest = Message::query()->orderBy('received_at')->orderBy('id')->first();

                if (! $oldest instanceof Message) {
                    break;
                }

                if ((int) $oldest->size_bytes === 0) {
                    break;
                }

                $deleted += app(DeleteMessage::class)((int) $oldest->getKey());
            }
        }

        $this->info("Pruned {$deleted} Sink messages.");

        return self::SUCCESS;
    }
}
