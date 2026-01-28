<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProductListing;
use App\Models\Retailer;
use App\Notifications\DataFreshnessAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class DataFreshnessMonitorCommand extends Command
{
    protected $signature = 'monitor:data-freshness
                            {--alert : Send alerts for critical issues}
                            {--report : Generate and display detailed report}';

    protected $description = 'Monitor data freshness and alert on stale data';

    public function handle(): int
    {
        $staleProductThresholdDays = config('monitoring.stale_product_threshold_days', 2);
        $retailerCrawlThresholdHours = config('monitoring.retailer_crawl_threshold_hours', 24);
        $highFailureRateThreshold = config('monitoring.high_failure_rate_threshold', 0.2);

        info('Data Freshness Monitor - '.now()->format('Y-m-d H:i:s'));
        $this->newLine();

        $issues = $this->detectIssues(
            $staleProductThresholdDays,
            $retailerCrawlThresholdHours,
            $highFailureRateThreshold
        );

        if ($this->option('report')) {
            $this->displayReport($issues);
        }

        $criticalIssues = $this->filterCriticalIssues($issues);

        if (! empty($criticalIssues)) {
            if ($this->option('alert')) {
                $this->sendAlerts($criticalIssues);
            }

            warning('Found '.count($criticalIssues).' critical issue(s).');

            return self::FAILURE;
        }

        info('All systems healthy - no critical issues detected.');

        return self::SUCCESS;
    }

    /**
     * Detect all data freshness issues.
     *
     * @return array<string, mixed>
     */
    protected function detectIssues(
        int $staleProductThresholdDays,
        int $retailerCrawlThresholdHours,
        float $highFailureRateThreshold
    ): array {
        return [
            'stale_products' => $this->checkStaleProducts($staleProductThresholdDays),
            'inactive_retailers' => $this->checkInactiveRetailers($retailerCrawlThresholdHours),
            'high_failure_retailers' => $this->checkHighFailureRates($highFailureRateThreshold),
        ];
    }

    /**
     * Check for products not scraped in X days.
     *
     * @return array<string, mixed>
     */
    protected function checkStaleProducts(int $thresholdDays): array
    {
        $staleProducts = ProductListing::query()
            ->where(function ($query) use ($thresholdDays) {
                $query->whereNull('last_scraped_at')
                    ->orWhere('last_scraped_at', '<', now()->subDays($thresholdDays));
            })
            ->with('retailer:id,name,slug')
            ->get();

        $byRetailer = $staleProducts->groupBy('retailer.slug')->map(function ($listings) {
            return [
                'retailer_name' => $listings->first()->retailer->name,
                'count' => $listings->count(),
                'oldest_scrape' => $listings->min('last_scraped_at')?->diffForHumans() ?? 'Never',
            ];
        });

        return [
            'total' => $staleProducts->count(),
            'threshold_days' => $thresholdDays,
            'by_retailer' => $byRetailer->toArray(),
            'is_critical' => $staleProducts->count() > 100,
        ];
    }

    /**
     * Check for retailers with no successful crawls in 24h.
     *
     * @return array<string, mixed>
     */
    protected function checkInactiveRetailers(int $thresholdHours): array
    {
        $inactiveRetailers = Retailer::query()
            ->whereIn('status', \App\Enums\RetailerStatus::crawlableStatuses())
            ->where(function ($query) use ($thresholdHours) {
                $query->whereNull('last_crawled_at')
                    ->orWhere('last_crawled_at', '<', now()->subHours($thresholdHours));
            })
            ->get()
            ->map(function (Retailer $retailer) {
                return [
                    'name' => $retailer->name,
                    'slug' => $retailer->slug,
                    'last_crawled' => $retailer->last_crawled_at?->diffForHumans() ?? 'Never',
                    'status' => $retailer->status->value,
                    'consecutive_failures' => $retailer->consecutive_failures,
                ];
            });

        return [
            'total' => $inactiveRetailers->count(),
            'threshold_hours' => $thresholdHours,
            'retailers' => $inactiveRetailers->toArray(),
            'is_critical' => $inactiveRetailers->count() > 0,
        ];
    }

    /**
     * Check for high failure rates per retailer.
     *
     * @return array<string, mixed>
     */
    protected function checkHighFailureRates(float $threshold): array
    {
        $cacheKey = 'retailer_failure_rates_'.now()->format('YmdH');

        $failureStats = Cache::remember($cacheKey, now()->addHour(), function () use ($threshold) {
            return Retailer::query()
                ->whereIn('status', \App\Enums\RetailerStatus::crawlableStatuses())
                ->get()
                ->map(function (Retailer $retailer) use ($threshold) {
                    $totalListings = $retailer->productListings()->count();

                    if ($totalListings === 0) {
                        return null;
                    }

                    $failedRecently = $retailer->productListings()
                        ->where(function ($query) {
                            $query->where('last_scraped_at', '<', now()->subHours(24))
                                ->orWhereNull('last_scraped_at');
                        })
                        ->count();

                    $failureRate = $failedRecently / $totalListings;

                    if ($failureRate < $threshold) {
                        return null;
                    }

                    return [
                        'name' => $retailer->name,
                        'slug' => $retailer->slug,
                        'failure_rate' => round($failureRate * 100, 1),
                        'failed_listings' => $failedRecently,
                        'total_listings' => $totalListings,
                        'consecutive_failures' => $retailer->consecutive_failures,
                    ];
                })
                ->filter()
                ->values();
        });

        return [
            'total' => $failureStats->count(),
            'threshold_percent' => $threshold * 100,
            'retailers' => $failureStats->toArray(),
            'is_critical' => $failureStats->count() > 0,
        ];
    }

    /**
     * Filter only critical issues that require immediate attention.
     *
     * @param  array<string, mixed>  $issues
     * @return array<string, mixed>
     */
    protected function filterCriticalIssues(array $issues): array
    {
        $critical = [];

        if ($issues['stale_products']['is_critical']) {
            $critical['stale_products'] = $issues['stale_products'];
        }

        if ($issues['inactive_retailers']['is_critical']) {
            $critical['inactive_retailers'] = $issues['inactive_retailers'];
        }

        if ($issues['high_failure_retailers']['is_critical']) {
            $critical['high_failure_retailers'] = $issues['high_failure_retailers'];
        }

        return $critical;
    }

    /**
     * Display full health report.
     *
     * @param  array<string, mixed>  $issues
     */
    protected function displayReport(array $issues): void
    {
        info('=== Stale Products Report ===');
        $this->displayStaleProductsReport($issues['stale_products']);
        $this->newLine();

        info('=== Inactive Retailers Report ===');
        $this->displayInactiveRetailersReport($issues['inactive_retailers']);
        $this->newLine();

        info('=== High Failure Rate Report ===');
        $this->displayHighFailureRatesReport($issues['high_failure_retailers']);
        $this->newLine();
    }

    /**
     * @param  array<string, mixed>  $staleProducts
     */
    protected function displayStaleProductsReport(array $staleProducts): void
    {
        if ($staleProducts['total'] === 0) {
            info('✓ All products are fresh (scraped within '.$staleProducts['threshold_days'].' days)');

            return;
        }

        warning('Found '.$staleProducts['total'].' stale product listings');

        $rows = [];
        foreach ($staleProducts['by_retailer'] as $slug => $data) {
            $rows[] = [
                $data['retailer_name'],
                (string) $data['count'],
                $data['oldest_scrape'],
            ];
        }

        table(['Retailer', 'Stale Listings', 'Oldest Scrape'], $rows);
    }

    /**
     * @param  array<string, mixed>  $inactiveRetailers
     */
    protected function displayInactiveRetailersReport(array $inactiveRetailers): void
    {
        if ($inactiveRetailers['total'] === 0) {
            info('✓ All retailers crawled within '.$inactiveRetailers['threshold_hours'].' hours');

            return;
        }

        warning('Found '.$inactiveRetailers['total'].' inactive retailers');

        $rows = [];
        foreach ($inactiveRetailers['retailers'] as $retailer) {
            $rows[] = [
                $retailer['name'],
                $retailer['last_crawled'],
                $retailer['status'],
                (string) $retailer['consecutive_failures'],
            ];
        }

        table(['Retailer', 'Last Crawled', 'Status', 'Consecutive Failures'], $rows);
    }

    /**
     * @param  array<string, mixed>  $highFailureRates
     */
    protected function displayHighFailureRatesReport(array $highFailureRates): void
    {
        if ($highFailureRates['total'] === 0) {
            info('✓ All retailers have acceptable failure rates (< '.$highFailureRates['threshold_percent'].'%)');

            return;
        }

        warning('Found '.$highFailureRates['total'].' retailers with high failure rates');

        $rows = [];
        foreach ($highFailureRates['retailers'] as $retailer) {
            $rows[] = [
                $retailer['name'],
                $retailer['failure_rate'].'%',
                $retailer['failed_listings'].'/'.$retailer['total_listings'],
                (string) $retailer['consecutive_failures'],
            ];
        }

        table(['Retailer', 'Failure Rate', 'Failed/Total', 'Consecutive Failures'], $rows);
    }

    /**
     * Send alerts for critical issues.
     *
     * @param  array<string, mixed>  $criticalIssues
     */
    protected function sendAlerts(array $criticalIssues): void
    {
        $notificationChannels = config('monitoring.notification_channels', []);

        if (empty($notificationChannels)) {
            warning('No notification channels configured. Skipping alerts.');

            return;
        }

        Notification::route('slack', config('services.slack.notifications.channel'))
            ->route('mail', config('monitoring.alert_email'))
            ->notify(new DataFreshnessAlertNotification($criticalIssues));

        info('Alerts sent via: '.implode(', ', $notificationChannels));
    }
}
