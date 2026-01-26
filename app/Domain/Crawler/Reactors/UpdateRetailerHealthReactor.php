<?php

declare(strict_types=1);

namespace App\Domain\Crawler\Reactors;

use App\Domain\Crawler\Events\CrawlCompleted;
use App\Domain\Crawler\Events\CrawlFailed;
use App\Domain\Crawler\Events\CrawlStarted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

class UpdateRetailerHealthReactor extends Reactor
{
    private const HEALTH_WINDOW_HOURS = 24;

    private const CACHE_TTL_HOURS = 48;

    public function onCrawlCompleted(CrawlCompleted $event): void
    {
        $retailer = $this->getRetailerFromCrawl($event->crawlId);

        if ($retailer === null) {
            Log::warning('UpdateRetailerHealthReactor: Could not determine retailer for completed crawl', [
                'crawl_id' => $event->crawlId,
            ]);

            return;
        }

        $this->recordCrawlResult($retailer, true, $event->statistics['duration_seconds'] ?? null);
        $this->updateHealthMetrics($retailer);

        Log::debug('Retailer health updated after successful crawl', [
            'retailer' => $retailer,
            'crawl_id' => $event->crawlId,
            'products_discovered' => $event->productListingsDiscovered,
        ]);
    }

    public function onCrawlFailed(CrawlFailed $event): void
    {
        $retailer = $this->getRetailerFromCrawl($event->crawlId);

        if ($retailer === null) {
            Log::warning('UpdateRetailerHealthReactor: Could not determine retailer for failed crawl', [
                'crawl_id' => $event->crawlId,
            ]);

            return;
        }

        $this->recordCrawlResult($retailer, false, null);
        $this->updateHealthMetrics($retailer);

        Log::debug('Retailer health updated after failed crawl', [
            'retailer' => $retailer,
            'crawl_id' => $event->crawlId,
            'reason' => $event->reason,
        ]);
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
}
