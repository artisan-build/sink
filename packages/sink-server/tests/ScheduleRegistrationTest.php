<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('schedules sink maintenance hourly on the app schedule', function (): void {
    $events = collect($this->app->make(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) ($event->command ?? ''), 'sink:maintain'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});
