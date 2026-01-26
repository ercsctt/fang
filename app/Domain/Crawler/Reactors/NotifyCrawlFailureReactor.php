<?php

declare(strict_types=1);

namespace App\Domain\Crawler\Reactors;

use App\Domain\Crawler\Events\CrawlFailed;
use App\Domain\Crawler\Events\CrawlStarted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

class NotifyCrawlFailureReactor extends Reactor
{
    private const FAILURE_THRESHOLD = 3;

    private const FAILURE_WINDOW_MINUTES = 60;

    public function onCrawlFailed(CrawlFailed $event): void
    {
        $retailer = $this->getRetailerFromCrawl($event->crawlId);

        if ($retailer === null) {
            Log::warning('NotifyCrawlFailureReactor: Could not determine retailer for crawl', [
                'crawl_id' => $event->crawlId,
                'reason' => $event->reason,
            ]);

            return;
        }

        $cacheKey = $this->getFailureCacheKey($retailer);
        $failureCount = $this->incrementFailureCount($cacheKey);

        Log::info('Crawl failure recorded', [
            'crawl_id' => $event->crawlId,
            'retailer' => $retailer,
            'reason' => $event->reason,
            'context' => $event->context,
            'consecutive_failures' => $failureCount,
        ]);

        if ($failureCount >= self::FAILURE_THRESHOLD) {
            $this->sendNotification($retailer, $failureCount, $event);
        }
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

    private function getFailureCacheKey(string $retailer): string
    {
        return "crawler:failures:{$retailer}";
    }

    private function incrementFailureCount(string $cacheKey): int
    {
        $failureCount = Cache::get($cacheKey, 0) + 1;

        Cache::put($cacheKey, $failureCount, now()->addMinutes(self::FAILURE_WINDOW_MINUTES));

        return $failureCount;
    }

    public function resetFailureCount(string $retailer): void
    {
        Cache::forget($this->getFailureCacheKey($retailer));
    }

    private function sendNotification(string $retailer, int $failureCount, CrawlFailed $event): void
    {
        Log::error('ALERT: Multiple consecutive crawl failures detected', [
            'retailer' => $retailer,
            'failure_count' => $failureCount,
            'threshold' => self::FAILURE_THRESHOLD,
            'latest_crawl_id' => $event->crawlId,
            'latest_reason' => $event->reason,
            'latest_context' => $event->context,
            'message' => "Retailer '{$retailer}' has failed {$failureCount} consecutive crawls. Immediate attention required.",
        ]);

        // TODO: Add Slack/email notification here when configured
        // Notification::route('slack', config('services.slack.webhook'))
        //     ->notify(new CrawlerFailureNotification($retailer, $failureCount, $event));
    }
}
