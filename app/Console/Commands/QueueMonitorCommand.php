<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class QueueMonitorCommand extends Command
{
    protected $signature = 'queue:monitor
                            {--json : Output as JSON for machine-readable format}';

    protected $description = 'Monitor queue health and display statistics';

    public function handle(): int
    {
        $data = $this->gatherQueueStatistics();

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->displayStatistics($data);

        return self::SUCCESS;
    }

    /**
     * Gather all queue statistics from the database.
     *
     * @return array<string, mixed>
     */
    protected function gatherQueueStatistics(): array
    {
        return [
            'pending_jobs' => $this->getPendingJobsByQueue(),
            'processed_jobs' => $this->getProcessedJobsStats(),
            'failed_jobs' => $this->getFailedJobsStats(),
            'crawl_statistics' => $this->getCrawlStatistics(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get count of pending jobs grouped by queue.
     *
     * @return array<string, int>
     */
    protected function getPendingJobsByQueue(): array
    {
        $results = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as count'))
            ->groupBy('queue')
            ->get();

        $queues = [];
        foreach ($results as $row) {
            $queues[$row->queue] = $row->count;
        }

        return $queues;
    }

    /**
     * Get statistics about processed jobs from stored events.
     *
     * @return array<string, mixed>
     */
    protected function getProcessedJobsStats(): array
    {
        $now = now();
        $lastHour = $now->copy()->subHour();
        $lastDay = $now->copy()->subDay();

        // Count events from stored_events table which tracks job processing
        $lastHourCount = DB::table('stored_events')
            ->where('created_at', '>=', $lastHour)
            ->count();

        $lastDayCount = DB::table('stored_events')
            ->where('created_at', '>=', $lastDay)
            ->count();

        // Get event types from the last hour for breakdown
        $eventsByType = DB::table('stored_events')
            ->select('event_class', DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $lastHour)
            ->groupBy('event_class')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $typeBreakdown = [];
        foreach ($eventsByType as $event) {
            $className = class_basename($event->event_class);
            $typeBreakdown[$className] = $event->count;
        }

        return [
            'last_hour' => $lastHourCount,
            'last_day' => $lastDayCount,
            'by_type_last_hour' => $typeBreakdown,
        ];
    }

    /**
     * Get statistics about failed jobs.
     *
     * @return array<string, mixed>
     */
    protected function getFailedJobsStats(): array
    {
        $totalFailed = DB::table('failed_jobs')->count();

        $recentFailures = DB::table('failed_jobs')
            ->select('queue', 'failed_at', 'exception')
            ->orderByDesc('failed_at')
            ->limit(5)
            ->get();

        $failedByQueue = DB::table('failed_jobs')
            ->select('queue', DB::raw('COUNT(*) as count'))
            ->groupBy('queue')
            ->get();

        $byQueue = [];
        foreach ($failedByQueue as $row) {
            $byQueue[$row->queue] = $row->count;
        }

        $recent = [];
        foreach ($recentFailures as $failure) {
            // Extract first line of exception for summary
            $exceptionLines = explode("\n", $failure->exception);
            $exceptionSummary = mb_substr($exceptionLines[0], 0, 100);

            $recent[] = [
                'queue' => $failure->queue,
                'failed_at' => $failure->failed_at,
                'exception' => $exceptionSummary,
            ];
        }

        return [
            'total' => $totalFailed,
            'by_queue' => $byQueue,
            'recent' => $recent,
        ];
    }

    /**
     * Get crawl-specific statistics from stored events.
     *
     * @return array<string, mixed>
     */
    protected function getCrawlStatistics(): array
    {
        $lastDay = now()->subDay();

        // Get crawl-related events
        $crawlEvents = DB::table('stored_events')
            ->select('event_class', DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $lastDay)
            ->where('event_class', 'LIKE', '%Crawl%')
            ->groupBy('event_class')
            ->get();

        $stats = [];
        foreach ($crawlEvents as $event) {
            $className = class_basename($event->event_class);
            $stats[$className] = $event->count;
        }

        // Get product listings created/updated today
        $listingsCreatedToday = DB::table('product_listings')
            ->where('created_at', '>=', $lastDay)
            ->count();

        $listingsUpdatedToday = DB::table('product_listings')
            ->where('updated_at', '>=', $lastDay)
            ->where('created_at', '<', $lastDay)
            ->count();

        // Get reviews extracted today
        $reviewsToday = DB::table('product_listing_reviews')
            ->where('created_at', '>=', $lastDay)
            ->count();

        // Get price records today
        $pricesRecordedToday = DB::table('product_listing_prices')
            ->where('recorded_at', '>=', $lastDay)
            ->count();

        return [
            'events' => $stats,
            'listings_created_today' => $listingsCreatedToday,
            'listings_updated_today' => $listingsUpdatedToday,
            'reviews_extracted_today' => $reviewsToday,
            'prices_recorded_today' => $pricesRecordedToday,
        ];
    }

    /**
     * Display statistics using Laravel Prompts.
     *
     * @param  array<string, mixed>  $data
     */
    protected function displayStatistics(array $data): void
    {
        info('Queue Monitor - '.now()->format('Y-m-d H:i:s'));
        $this->newLine();

        // Pending Jobs
        note('Pending Jobs by Queue');
        if (empty($data['pending_jobs'])) {
            $this->line('  No pending jobs in queue.');
        } else {
            $rows = [];
            foreach ($data['pending_jobs'] as $queue => $count) {
                $rows[] = [$queue, (string) $count];
            }
            table(['Queue', 'Pending Jobs'], $rows);
        }
        $this->newLine();

        // Processed Jobs
        note('Processed Events');
        table(
            ['Period', 'Count'],
            [
                ['Last Hour', (string) $data['processed_jobs']['last_hour']],
                ['Last 24 Hours', (string) $data['processed_jobs']['last_day']],
            ]
        );

        if (! empty($data['processed_jobs']['by_type_last_hour'])) {
            $this->newLine();
            note('Events by Type (Last Hour)');
            $rows = [];
            foreach ($data['processed_jobs']['by_type_last_hour'] as $type => $count) {
                $rows[] = [$type, (string) $count];
            }
            table(['Event Type', 'Count'], $rows);
        }
        $this->newLine();

        // Failed Jobs
        note('Failed Jobs');
        if ($data['failed_jobs']['total'] === 0) {
            $this->line('  No failed jobs.');
        } else {
            warning('Total Failed Jobs: '.$data['failed_jobs']['total']);

            if (! empty($data['failed_jobs']['by_queue'])) {
                $rows = [];
                foreach ($data['failed_jobs']['by_queue'] as $queue => $count) {
                    $rows[] = [$queue, (string) $count];
                }
                table(['Queue', 'Failed Count'], $rows);
            }

            if (! empty($data['failed_jobs']['recent'])) {
                $this->newLine();
                note('Recent Failures');
                $rows = [];
                foreach ($data['failed_jobs']['recent'] as $failure) {
                    $rows[] = [
                        $failure['queue'],
                        Carbon::parse($failure['failed_at'])->diffForHumans(),
                        mb_substr($failure['exception'], 0, 60).'...',
                    ];
                }
                table(['Queue', 'Failed', 'Exception'], $rows);
            }
        }
        $this->newLine();

        // Crawl Statistics
        note('Crawl Statistics (Last 24 Hours)');
        table(
            ['Metric', 'Count'],
            [
                ['Listings Created', (string) $data['crawl_statistics']['listings_created_today']],
                ['Listings Updated', (string) $data['crawl_statistics']['listings_updated_today']],
                ['Reviews Extracted', (string) $data['crawl_statistics']['reviews_extracted_today']],
                ['Prices Recorded', (string) $data['crawl_statistics']['prices_recorded_today']],
            ]
        );

        if (! empty($data['crawl_statistics']['events'])) {
            $this->newLine();
            note('Crawl Events');
            $rows = [];
            foreach ($data['crawl_statistics']['events'] as $event => $count) {
                $rows[] = [$event, (string) $count];
            }
            table(['Event', 'Count'], $rows);
        }
    }
}
