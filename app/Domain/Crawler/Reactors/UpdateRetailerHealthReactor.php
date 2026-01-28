<?php

declare(strict_types=1);

namespace App\Domain\Crawler\Reactors;

use App\Domain\Crawler\Events\CrawlCompleted;
use App\Domain\Crawler\Events\CrawlFailed;
use App\Domain\Crawler\Events\CrawlStarted;
use App\Enums\RetailerStatus;
use App\Models\Retailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

class UpdateRetailerHealthReactor extends Reactor
{
    private const DEGRADED_THRESHOLD = 5;

    private const UNHEALTHY_THRESHOLD = 10;

    private const PAUSE_DURATION_HOURS = 1;

    private const HEALTH_WINDOW_HOURS = 24;

    private const CACHE_TTL_HOURS = 48;

    public function onCrawlCompleted(CrawlCompleted $event): void
    {
        $retailerSlug = $this->getRetailerFromCrawl($event->crawlId);

        if ($retailerSlug === null) {
            Log::warning('UpdateRetailerHealthReactor: Could not determine retailer for completed crawl', [
                'crawl_id' => $event->crawlId,
            ]);

            return;
        }

        $retailer = Retailer::query()->where('slug', $retailerSlug)->first();

        if (! $retailer) {
            Log::warning('UpdateRetailerHealthReactor: Retailer not found', [
                'crawl_id' => $event->crawlId,
                'retailer_slug' => $retailerSlug,
            ]);

            return;
        }

        // Reset consecutive failures and transition to Active status
        $updateData = [
            'consecutive_failures' => 0,
            'paused_until' => null,
        ];

        // Transition to Active if allowed
        if ($retailer->status->canTransitionTo(RetailerStatus::Active)) {
            $updateData['status'] = RetailerStatus::Active;
        }

        $retailer->update($updateData);

        // Update cache-based health metrics
        $this->recordCrawlResult($retailerSlug, true, $event->statistics['duration_seconds'] ?? null);
        $this->updateHealthMetrics($retailerSlug);

        Log::debug('Retailer health updated after successful crawl', [
            'retailer' => $retailerSlug,
            'crawl_id' => $event->crawlId,
            'products_discovered' => $event->productListingsDiscovered,
            'status' => $retailer->fresh()->status->value,
        ]);
    }

    public function onCrawlFailed(CrawlFailed $event): void
    {
        $retailerSlug = $this->getRetailerFromCrawl($event->crawlId);

        if ($retailerSlug === null) {
            Log::warning('UpdateRetailerHealthReactor: Could not determine retailer for failed crawl', [
                'crawl_id' => $event->crawlId,
            ]);

            return;
        }

        $retailer = Retailer::query()->where('slug', $retailerSlug)->first();

        if (! $retailer) {
            Log::warning('UpdateRetailerHealthReactor: Retailer not found', [
                'crawl_id' => $event->crawlId,
                'retailer_slug' => $retailerSlug,
            ]);

            return;
        }

        // Increment consecutive failures and update last_failure_at
        $newConsecutiveFailures = $retailer->consecutive_failures + 1;

        $updateData = [
            'consecutive_failures' => $newConsecutiveFailures,
            'last_failure_at' => now(),
        ];

        // Apply circuit breaker logic with state transitions
        $newStatus = $this->determineStatus($retailer->status, $newConsecutiveFailures);

        // Validate and apply transition
        if ($retailer->status->canTransitionTo($newStatus)) {
            $updateData['status'] = $newStatus;

            // If transitioning to Failed, pause the retailer (but don't extend if already paused)
            if ($newStatus === RetailerStatus::Failed && ! $retailer->isPaused()) {
                $updateData['paused_until'] = now()->addHours(self::PAUSE_DURATION_HOURS);

                Log::error('CIRCUIT BREAKER ACTIVATED: Retailer failed due to consecutive failures', [
                    'retailer' => $retailerSlug,
                    'retailer_id' => $retailer->id,
                    'consecutive_failures' => $newConsecutiveFailures,
                    'threshold' => self::UNHEALTHY_THRESHOLD,
                    'previous_status' => $retailer->status->value,
                    'new_status' => $newStatus->value,
                    'paused_until' => $updateData['paused_until']->toIso8601String(),
                    'pause_duration_hours' => self::PAUSE_DURATION_HOURS,
                ]);
            }
        } else {
            Log::warning('Cannot transition retailer status after failure', [
                'retailer' => $retailerSlug,
                'current_status' => $retailer->status->value,
                'attempted_status' => $newStatus->value,
                'consecutive_failures' => $newConsecutiveFailures,
            ]);
        }

        $retailer->update($updateData);

        // Update cache-based health metrics
        $this->recordCrawlResult($retailerSlug, false, null);
        $this->updateHealthMetrics($retailerSlug);

        Log::debug('Retailer health updated after failed crawl', [
            'retailer' => $retailerSlug,
            'crawl_id' => $event->crawlId,
            'reason' => $event->reason,
            'consecutive_failures' => $newConsecutiveFailures,
            'status' => $retailer->fresh()->status->value,
        ]);
    }

    private function determineStatus(RetailerStatus $currentStatus, int $consecutiveFailures): RetailerStatus
    {
        // Circuit breaker: transition through states based on failure count
        if ($consecutiveFailures >= self::UNHEALTHY_THRESHOLD) {
            return RetailerStatus::Failed;
        }

        if ($consecutiveFailures >= self::DEGRADED_THRESHOLD) {
            return RetailerStatus::Degraded;
        }

        return RetailerStatus::Active;
    }

    private function getRetailerFromCrawl(string $crawlId): ?string
    {
        $crawlStartedEvent = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $crawlId)
            ->where('event_class', CrawlStarted::class)
            ->first();

        if (! $crawlStartedEvent) {
            return null;
        }

        $eventProperties = $crawlStartedEvent->event_properties;

        return $eventProperties['retailer'] ?? null;
    }

    private function recordCrawlResult(string $retailer, bool $success, ?float $durationSeconds): void
    {
        $resultsKey = $this->getCrawlResultsKey($retailer);
        $results = Cache::get($resultsKey, []);

        $results[] = [
            'success' => $success,
            'duration' => $durationSeconds,
            'timestamp' => now()->timestamp,
        ];

        // Keep only results within the health window
        $cutoff = now()->subHours(self::HEALTH_WINDOW_HOURS)->timestamp;
        $results = array_filter($results, fn (array $result) => $result['timestamp'] >= $cutoff);

        Cache::put($resultsKey, array_values($results), now()->addHours(self::CACHE_TTL_HOURS));
    }

    private function updateHealthMetrics(string $retailer): void
    {
        $resultsKey = $this->getCrawlResultsKey($retailer);
        $results = Cache::get($resultsKey, []);

        if (empty($results)) {
            return;
        }

        $successCount = count(array_filter($results, fn (array $r) => $r['success']));
        $totalCount = count($results);
        $successRate = $totalCount > 0 ? ($successCount / $totalCount) * 100 : 0;

        $durations = array_filter(
            array_column($results, 'duration'),
            fn ($d) => $d !== null
        );
        $avgDuration = ! empty($durations) ? array_sum($durations) / count($durations) : null;

        $lastSuccess = null;
        foreach (array_reverse($results) as $result) {
            if ($result['success']) {
                $lastSuccess = $result['timestamp'];
                break;
            }
        }

        $healthKey = $this->getHealthMetricsKey($retailer);
        Cache::put($healthKey, [
            'success_rate' => round($successRate, 2),
            'total_crawls' => $totalCount,
            'successful_crawls' => $successCount,
            'failed_crawls' => $totalCount - $successCount,
            'avg_duration_seconds' => $avgDuration !== null ? round($avgDuration, 2) : null,
            'last_successful_crawl' => $lastSuccess,
            'updated_at' => now()->timestamp,
        ], now()->addHours(self::CACHE_TTL_HOURS));
    }

    private function getCrawlResultsKey(string $retailer): string
    {
        return "crawler:health:results:{$retailer}";
    }

    private function getHealthMetricsKey(string $retailer): string
    {
        return "crawler:health:metrics:{$retailer}";
    }

    /**
     * Get health metrics for a retailer.
     *
     * @return array{
     *     success_rate: float,
     *     total_crawls: int,
     *     successful_crawls: int,
     *     failed_crawls: int,
     *     avg_duration_seconds: float|null,
     *     last_successful_crawl: int|null,
     *     updated_at: int
     * }|null
     */
    public static function getHealthMetrics(string $retailer): ?array
    {
        return Cache::get("crawler:health:metrics:{$retailer}");
    }

    /**
     * Manually reset the health status for a retailer.
     */
    public static function resetHealth(string $retailerSlug): void
    {
        $retailer = Retailer::query()->where('slug', $retailerSlug)->first();

        if ($retailer) {
            $updateData = [
                'consecutive_failures' => 0,
                'paused_until' => null,
                'last_failure_at' => null,
            ];

            // Transition to Active if allowed
            if ($retailer->status->canTransitionTo(RetailerStatus::Active)) {
                $updateData['status'] = RetailerStatus::Active;
            }

            $retailer->update($updateData);

            // Clear cache-based metrics
            Cache::forget("crawler:health:results:{$retailerSlug}");
            Cache::forget("crawler:health:metrics:{$retailerSlug}");

            Log::info('Retailer health manually reset', [
                'retailer' => $retailerSlug,
                'retailer_id' => $retailer->id,
                'status' => $retailer->fresh()->status->value,
            ]);
        }
    }
}
