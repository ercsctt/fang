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
use Illuminate\Support\Str;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

class CircuitBreakerReactor extends Reactor
{
    private const FAILURE_RATE_THRESHOLD = 50;

    private const TRACKING_WINDOW_MINUTES = 60;

    private const COOLDOWN_MINUTES = 30;

    private const MIN_CRAWLS_FOR_EVALUATION = 3;

    public function onCrawlFailed(CrawlFailed $event): void
    {
        $retailer = $this->getRetailerFromCrawl($event->crawlId);

        if ($retailer === null) {
            return;
        }

        $this->recordCrawlOutcome($retailer, false);
        $this->evaluateCircuitBreaker($retailer);
    }

    public function onCrawlCompleted(CrawlCompleted $event): void
    {
        $retailer = $this->getRetailerFromCrawl($event->crawlId);

        if ($retailer === null) {
            return;
        }

        $this->recordCrawlOutcome($retailer, true);

        // Reset failure tracking on success if circuit breaker was triggered
        if ($this->isCircuitOpen($retailer)) {
            $this->closeCircuit($retailer);
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

    private function recordCrawlOutcome(string $retailer, bool $success): void
    {
        $key = $this->getTrackingKey($retailer);
        $outcomes = Cache::get($key, []);

        $outcomes[] = [
            'success' => $success,
            'timestamp' => now()->timestamp,
        ];

        // Keep only outcomes within the tracking window
        $cutoff = now()->subMinutes(self::TRACKING_WINDOW_MINUTES)->timestamp;
        $outcomes = array_filter($outcomes, fn (array $o) => $o['timestamp'] >= $cutoff);

        Cache::put($key, array_values($outcomes), now()->addMinutes(self::TRACKING_WINDOW_MINUTES + 5));
    }

    private function evaluateCircuitBreaker(string $retailer): void
    {
        $key = $this->getTrackingKey($retailer);
        $outcomes = Cache::get($key, []);

        $totalCrawls = count($outcomes);

        if ($totalCrawls < self::MIN_CRAWLS_FOR_EVALUATION) {
            return;
        }

        $failures = count(array_filter($outcomes, fn (array $o) => ! $o['success']));
        $failureRate = ($failures / $totalCrawls) * 100;

        if ($failureRate >= self::FAILURE_RATE_THRESHOLD) {
            $this->openCircuit($retailer, $failureRate, $failures, $totalCrawls);
        }
    }

    private function openCircuit(string $retailer, float $failureRate, int $failures, int $totalCrawls): void
    {
        if ($this->isCircuitOpen($retailer)) {
            return;
        }

        $retailerModel = Retailer::query()
            ->where('slug', Str::slug($retailer))
            ->first();

        if ($retailerModel && $retailerModel->status->isAvailableForCrawling()) {
            $retailerModel->update(['status' => RetailerStatus::Failed]);

            Log::error('CIRCUIT BREAKER ACTIVATED: Retailer disabled due to high failure rate', [
                'retailer' => $retailer,
                'retailer_id' => $retailerModel->id,
                'failure_rate' => round($failureRate, 2),
                'failures' => $failures,
                'total_crawls' => $totalCrawls,
                'threshold' => self::FAILURE_RATE_THRESHOLD,
                'window_minutes' => self::TRACKING_WINDOW_MINUTES,
                'cooldown_minutes' => self::COOLDOWN_MINUTES,
                'message' => "Retailer '{$retailer}' has been disabled. {$failures}/{$totalCrawls} crawls failed ({$failureRate}%) in the last hour.",
            ]);

            // Set cooldown timer
            Cache::put(
                $this->getCooldownKey($retailer),
                now()->timestamp,
                now()->addMinutes(self::COOLDOWN_MINUTES)
            );

            Cache::put($this->getCircuitOpenKey($retailer), true, now()->addMinutes(self::COOLDOWN_MINUTES));
        }
    }

    private function closeCircuit(string $retailer): void
    {
        Cache::forget($this->getCircuitOpenKey($retailer));
        Cache::forget($this->getCooldownKey($retailer));
        Cache::forget($this->getTrackingKey($retailer));

        Log::info('Circuit breaker reset for retailer', [
            'retailer' => $retailer,
            'message' => "Retailer '{$retailer}' circuit breaker has been reset after successful crawl.",
        ]);
    }

    private function isCircuitOpen(string $retailer): bool
    {
        return Cache::get($this->getCircuitOpenKey($retailer), false);
    }

    private function getTrackingKey(string $retailer): string
    {
        return "crawler:circuit:tracking:{$retailer}";
    }

    private function getCircuitOpenKey(string $retailer): string
    {
        return "crawler:circuit:open:{$retailer}";
    }

    private function getCooldownKey(string $retailer): string
    {
        return "crawler:circuit:cooldown:{$retailer}";
    }

    /**
     * Check if the circuit breaker is currently open for a retailer.
     */
    public static function isOpen(string $retailer): bool
    {
        return Cache::get("crawler:circuit:open:{$retailer}", false);
    }

    /**
     * Get the cooldown expiry timestamp for a retailer.
     */
    public static function getCooldownExpiry(string $retailer): ?int
    {
        return Cache::get("crawler:circuit:cooldown:{$retailer}");
    }

    /**
     * Manually reset the circuit breaker for a retailer.
     */
    public static function reset(string $retailer): void
    {
        Cache::forget("crawler:circuit:open:{$retailer}");
        Cache::forget("crawler:circuit:cooldown:{$retailer}");
        Cache::forget("crawler:circuit:tracking:{$retailer}");

        $retailerModel = Retailer::query()
            ->where('slug', Str::slug($retailer))
            ->first();

        if ($retailerModel && $retailerModel->status === RetailerStatus::Failed) {
            $retailerModel->update(['status' => RetailerStatus::Active]);

            Log::info('Circuit breaker manually reset for retailer', [
                'retailer' => $retailer,
                'retailer_id' => $retailerModel->id,
            ]);
        }
    }
}
