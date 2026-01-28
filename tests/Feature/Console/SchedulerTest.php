<?php

declare(strict_types=1);

use App\Jobs\CleanupOrphanedImagesJob;
use Illuminate\Console\Scheduling\Schedule;

describe('Scheduler', function () {
    it('schedules cleanup orphaned images job to run daily', function () {
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())
            ->filter(function ($event) {
                // Check if this is a job callback event for CleanupOrphanedImagesJob
                if (method_exists($event, 'getSummaryForDisplay')) {
                    $summary = $event->getSummaryForDisplay();
                    if (str_contains($summary, 'CleanupOrphanedImagesJob')) {
                        return true;
                    }
                }

                return str_contains($event->description ?? '', 'orphaned images');
            });

        expect($events)->toHaveCount(1);

        $event = $events->first();

        expect($event->description)->toBe('Daily cleanup of orphaned images not accessed in 30+ days');
        expect($event->expression)->toBe('0 4 * * *'); // Daily at 04:00
        expect($event->timezone)->toBe('Europe/London');
    });

    it('schedules crawler dispatch command to run daily', function () {
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'crawler:dispatch-all'));

        expect($events)->toHaveCount(1);

        $event = $events->first();

        expect($event->description)->toBe('Daily product listing crawl for all active retailers');
        expect($event->expression)->toBe('0 2 * * *'); // Daily at 02:00
        expect($event->timezone)->toBe('Europe/London');
    });
});
