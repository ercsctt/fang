<?php

declare(strict_types=1);

namespace App\Domain\Crawler\Projectors;

use App\Domain\Crawler\Events\CrawlCompleted;
use App\Domain\Crawler\Events\CrawlFailed;
use App\Domain\Crawler\Events\CrawlStarted;
use App\Models\CrawlStatistic;
use App\Models\Retailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

class CrawlStatisticsProjector extends Projector
{
    public function onCrawlStarted(CrawlStarted $event): void
    {
        $retailer = $this->findRetailer($event->retailer);

        if (! $retailer) {
            Log::warning('CrawlStatisticsProjector: Retailer not found for crawl statistics', [
                'retailer' => $event->retailer,
                'crawl_id' => $event->crawlId,
            ]);

            return;
        }

        $statistic = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('date', today())
            ->first();

        if ($statistic) {
            $statistic->increment('crawls_started');
        } else {
            CrawlStatistic::create([
                'retailer_id' => $retailer->id,
                'date' => today(),
                'crawls_started' => 1,
                'crawls_completed' => 0,
                'crawls_failed' => 0,
                'listings_discovered' => 0,
                'details_extracted' => 0,
                'average_duration_ms' => null,
            ]);
        }
    }

    public function onCrawlCompleted(CrawlCompleted $event): void
    {
        $retailerSlug = $this->getRetailerFromCrawl($event->crawlId);

        if ($retailerSlug === null) {
            Log::warning('CrawlStatisticsProjector: Could not determine retailer for completed crawl', [
                'crawl_id' => $event->crawlId,
            ]);

            return;
        }

        $retailer = Retailer::query()->where('slug', $retailerSlug)->first();

        if (! $retailer) {
            Log::warning('CrawlStatisticsProjector: Retailer not found', [
                'crawl_id' => $event->crawlId,
                'retailer_slug' => $retailerSlug,
            ]);

            return;
        }

        $durationMs = $event->statistics['duration_ms'] ?? null;
        $listingsDiscovered = $event->productListingsDiscovered;

        $statistic = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('date', today())
            ->first();

        if ($statistic) {
            $newAvgDuration = $this->calculateNewAverageDuration(
                $statistic->average_duration_ms,
                $statistic->crawls_completed,
                $durationMs
            );

            $statistic->increment('crawls_completed');
            $statistic->increment('listings_discovered', $listingsDiscovered);
            $statistic->update([
                'average_duration_ms' => $newAvgDuration,
            ]);
        } else {
            CrawlStatistic::create([
                'retailer_id' => $retailer->id,
                'date' => today(),
                'crawls_started' => 0,
                'crawls_completed' => 1,
                'crawls_failed' => 0,
                'listings_discovered' => $listingsDiscovered,
                'details_extracted' => 0,
                'average_duration_ms' => $durationMs,
            ]);
        }
    }

    public function onCrawlFailed(CrawlFailed $event): void
    {
        $retailerSlug = $this->getRetailerFromCrawl($event->crawlId);

        if ($retailerSlug === null) {
            Log::warning('CrawlStatisticsProjector: Could not determine retailer for failed crawl', [
                'crawl_id' => $event->crawlId,
            ]);

            return;
        }

        $retailer = Retailer::query()->where('slug', $retailerSlug)->first();

        if (! $retailer) {
            Log::warning('CrawlStatisticsProjector: Retailer not found', [
                'crawl_id' => $event->crawlId,
                'retailer_slug' => $retailerSlug,
            ]);

            return;
        }

        $statistic = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('date', today())
            ->first();

        if ($statistic) {
            $statistic->increment('crawls_failed');
        } else {
            CrawlStatistic::create([
                'retailer_id' => $retailer->id,
                'date' => today(),
                'crawls_started' => 0,
                'crawls_completed' => 0,
                'crawls_failed' => 1,
                'listings_discovered' => 0,
                'details_extracted' => 0,
                'average_duration_ms' => null,
            ]);
        }
    }

    private function findRetailer(string $retailerName): ?Retailer
    {
        return Retailer::query()
            ->where('slug', Str::slug($retailerName))
            ->first();
    }

    /**
     * Get the retailer slug from a crawl by looking up the CrawlStarted event.
     */
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

    /**
     * Calculate a running average duration.
     */
    private function calculateNewAverageDuration(?int $currentAvg, int $completedCount, ?int $newDuration): ?int
    {
        if ($newDuration === null) {
            return $currentAvg;
        }

        if ($currentAvg === null || $completedCount === 0) {
            return $newDuration;
        }

        $totalDuration = ($currentAvg * $completedCount) + $newDuration;
        $newCount = $completedCount + 1;

        return (int) round($totalDuration / $newCount);
    }
}
