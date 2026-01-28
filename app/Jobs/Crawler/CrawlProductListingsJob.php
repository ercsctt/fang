<?php

declare(strict_types=1);

namespace App\Jobs\Crawler;

use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Proxies\BrightDataProxyAdapter;
use App\Crawler\Scrapers\BaseCrawler;
use App\Domain\Crawler\Aggregates\CrawlAggregate;
use App\Models\Retailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CrawlProductListingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly string $crawlerClass,
        private readonly string $url,
        private readonly ?string $crawlId = null,
        private readonly bool $useAdvancedAdapter = true,
    ) {}

    public function handle(): void
    {
        $crawlId = $this->crawlId ?? Str::uuid()->toString();

        Log::info('Starting crawl job', [
            'crawl_id' => $crawlId,
            'crawler' => $this->crawlerClass,
            'url' => $this->url,
            'use_advanced_adapter' => $this->useAdvancedAdapter,
        ]);

        // Verify retailer can be crawled
        /** @var BaseCrawler $tempCrawler */
        $tempCrawler = new $this->crawlerClass(new GuzzleHttpAdapter);
        $retailerSlug = $tempCrawler->getRetailerName();

        $retailer = Retailer::query()->where('slug', $retailerSlug)->first();

        if (! $retailer) {
            Log::warning('Retailer not found, skipping crawl', [
                'crawl_id' => $crawlId,
                'retailer_slug' => $retailerSlug,
            ]);

            return;
        }

        if (! $retailer->isAvailableForCrawling()) {
            Log::info('Retailer not available for crawling, skipping job', [
                'crawl_id' => $crawlId,
                'retailer' => $retailerSlug,
                'status' => $retailer->status->value,
            ]);

            return;
        }

        // Create HTTP adapter based on configuration
        $httpAdapter = $this->createHttpAdapter();

        // Instantiate the crawler
        /** @var BaseCrawler $crawler */
        $crawler = new $this->crawlerClass($httpAdapter);

        // Initialize event sourcing aggregate
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(
            url: $this->url,
            retailer: $crawler->getRetailerName(),
            metadata: [
                'crawler_class' => $this->crawlerClass,
                'use_advanced_adapter' => $this->useAdvancedAdapter,
                'adapter_type' => get_class($httpAdapter),
            ]
        );

        // Track discovered URLs
        $discoveredCount = 0;
        $startTime = microtime(true);

        try {
            // Crawl and extract product listing URLs
            foreach ($crawler->crawl($this->url) as $dto) {
                if ($dto instanceof ProductListingUrl) {
                    $discoveredCount++;

                    Log::info('Discovered product listing', [
                        'crawl_id' => $crawlId,
                        'url' => $dto->url,
                        'retailer' => $dto->retailer,
                        'category' => $dto->category,
                    ]);

                    // Record in event sourcing aggregate
                    $aggregate->recordProductListingDiscovered(
                        url: $dto->url,
                        retailer: $dto->retailer,
                        category: $dto->category,
                        metadata: $dto->metadata ?? []
                    );
                }
            }

            $duration = microtime(true) - $startTime;

            // Mark crawl as completed
            $aggregate->completeCrawl([
                'duration_seconds' => round($duration, 2),
                'discovered_count' => $discoveredCount,
            ])->persist();

            Log::info('Crawl job completed successfully', [
                'crawl_id' => $crawlId,
                'url' => $this->url,
                'discovered_count' => $discoveredCount,
                'duration' => round($duration, 2).'s',
            ]);

            // Respect rate limits - sleep before next job
            if ($discoveredCount > 0) {
                $delayMs = $crawler->getRequestDelay();
                usleep($delayMs * 1000);
            }
        } catch (\Exception $e) {
            Log::error('Crawl job failed', [
                'crawl_id' => $crawlId,
                'url' => $this->url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark crawl as failed in event sourcing
            $aggregate->markAsFailed(
                reason: $e->getMessage(),
                context: [
                    'url' => $this->url,
                    'exception_class' => get_class($e),
                ]
            )->persist();

            throw $e;
        }
    }

    /**
     * Create the appropriate HTTP adapter.
     */
    private function createHttpAdapter(): HttpAdapterInterface
    {
        $adapter = new GuzzleHttpAdapter(
            rotateUserAgent: $this->useAdvancedAdapter,
            rotateProxy: $this->useAdvancedAdapter,
        );

        // Configure proxy if BrightData is configured
        if (config('services.brightdata.username') && config('services.brightdata.password')) {
            $proxyAdapter = new BrightDataProxyAdapter;
            $adapter->withProxy($proxyAdapter);

            Log::info('Using BrightData proxy for crawling', [
                'zone' => config('services.brightdata.zone'),
                'country' => config('services.brightdata.country'),
            ]);
        } else {
            Log::warning('BrightData credentials not configured, crawling without proxy');
        }

        return $adapter;
    }

    /**
     * Get the tags for monitoring this job.
     */
    public function tags(): array
    {
        return [
            'crawler',
            'product-listings',
            basename(str_replace('\\', '/', $this->crawlerClass)),
        ];
    }
}
