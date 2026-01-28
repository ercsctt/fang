<?php

declare(strict_types=1);

namespace App\Jobs\Crawler;

use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Proxies\BrightDataProxyAdapter;
use App\Crawler\Scrapers\BaseCrawler;
use App\Domain\Crawler\Events\CrawlJobFailed;
use App\Domain\Crawler\Events\PriceDropped;
use App\Domain\Crawler\Reactors\PriceDropReactor;
use App\Models\ProductListing;
use App\Services\ImageCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CrawlProductDetailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly string $crawlerClass,
        private readonly int $productListingId,
        private readonly string $url,
    ) {}

    public function handle(): void
    {
        Log::info('Starting product details crawl job', [
            'product_listing_id' => $this->productListingId,
            'crawler' => $this->crawlerClass,
            'url' => $this->url,
        ]);

        // Find the ProductListing
        $listing = ProductListing::find($this->productListingId);

        if (! $listing) {
            Log::error('Product listing not found', [
                'product_listing_id' => $this->productListingId,
            ]);

            return;
        }

        // Create HTTP adapter with proxy support
        $httpAdapter = $this->createHttpAdapter();

        // Instantiate the crawler
        /** @var BaseCrawler $crawler */
        $crawler = new $this->crawlerClass($httpAdapter);

        $startTime = microtime(true);
        $detailsFound = false;

        try {
            // Crawl the URL and extract product details
            foreach ($crawler->crawl($this->url) as $dto) {
                if ($dto instanceof ProductDetails) {
                    $detailsFound = true;
                    $this->updateListing($listing, $dto);

                    Log::info('Product details extracted successfully', [
                        'product_listing_id' => $this->productListingId,
                        'title' => $dto->title,
                        'price_pence' => $dto->pricePence,
                        'in_stock' => $dto->inStock,
                    ]);

                    break; // Only process the first ProductDetails DTO
                }
            }

            $duration = microtime(true) - $startTime;

            if (! $detailsFound) {
                Log::warning('No product details extracted from page', [
                    'product_listing_id' => $this->productListingId,
                    'url' => $this->url,
                    'duration' => round($duration, 2).'s',
                ]);

                return;
            }

            Log::info('Product details crawl job completed successfully', [
                'product_listing_id' => $this->productListingId,
                'url' => $this->url,
                'duration' => round($duration, 2).'s',
            ]);

            // Dispatch product matching job
            MatchProductListingJob::dispatch($this->productListingId)
                ->onQueue('default');

            // Respect rate limits - sleep before next job
            $delayMs = $crawler->getRequestDelay();
            usleep($delayMs * 1000);
        } catch (\Exception $e) {
            Log::error('Product details crawl job failed', [
                'product_listing_id' => $this->productListingId,
                'url' => $this->url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Create the appropriate HTTP adapter.
     */
    private function createHttpAdapter(): HttpAdapterInterface
    {
        $adapter = new GuzzleHttpAdapter(
            rotateUserAgent: true,
            rotateProxy: true,
        );

        // Configure proxy if BrightData is configured
        if (config('services.brightdata.username') && config('services.brightdata.password')) {
            $proxyAdapter = new BrightDataProxyAdapter;
            $adapter->withProxy($proxyAdapter);

            Log::info('Using BrightData proxy for product details crawl', [
                'zone' => config('services.brightdata.zone'),
                'country' => config('services.brightdata.country'),
            ]);
        } else {
            Log::warning('BrightData credentials not configured, crawling without proxy');
        }

        return $adapter;
    }

    /**
     * Update the listing with extracted product details.
     */
    private function updateListing(ProductListing $listing, ProductDetails $details): void
    {
        $oldPrice = $listing->price_pence;

        $images = $this->processImages($details->images);

        $listing->update([
            'title' => $details->title,
            'description' => $details->description,
            'price_pence' => $details->pricePence,
            'original_price_pence' => $details->originalPricePence,
            'currency' => $details->currency,
            'weight_grams' => $details->weightGrams,
            'quantity' => $details->quantity,
            'brand' => $details->brand,
            'category' => $details->category ?? $listing->category,
            'images' => $images,
            'ingredients' => $details->ingredients,
            'nutritional_info' => $details->nutritionalInfo,
            'in_stock' => $details->inStock,
            'stock_quantity' => $details->stockQuantity,
            'external_id' => $details->externalId,
            'barcode' => $details->barcode,
            'last_scraped_at' => now(),
        ]);

        // Record price history if price changed
        if ($oldPrice !== null && $oldPrice !== $details->pricePence) {
            $listing->recordPrice();

            // Check for significant price drop and emit event
            $this->checkForPriceDrop($listing, $oldPrice, $details->pricePence);
        }
    }

    /**
     * Check if a significant price drop occurred and emit event.
     */
    private function checkForPriceDrop(ProductListing $listing, int $oldPricePence, int $newPricePence): void
    {
        if ($newPricePence >= $oldPricePence) {
            return;
        }

        $dropPercentage = PriceDropReactor::calculateDropPercentage($oldPricePence, $newPricePence);
        $thresholdPercent = config('services.price_alerts.threshold_percent', 20);

        if ($dropPercentage < $thresholdPercent) {
            return;
        }

        $retailerName = $listing->retailer?->name ?? 'Unknown Retailer';

        event(new PriceDropped(
            productListingId: $listing->id,
            productTitle: $listing->title ?? 'Unknown Product',
            retailerName: $retailerName,
            productUrl: $listing->url,
            oldPricePence: $oldPricePence,
            newPricePence: $newPricePence,
            dropPercentage: $dropPercentage,
        ));

        Log::info('Price drop event emitted', [
            'product_listing_id' => $listing->id,
            'drop_percentage' => $dropPercentage,
            'threshold' => $thresholdPercent,
        ]);
    }

    /**
     * Get the tags for monitoring this job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'crawler',
            'product-details',
            'listing:'.$this->productListingId,
        ];
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $retailerSlug = $this->getRetailerSlugFromCrawlerClass();

        event(new CrawlJobFailed(
            crawlId: Str::uuid()->toString(),
            retailerSlug: $retailerSlug,
            url: $this->url,
            errorMessage: $exception->getMessage(),
            attemptNumber: $this->attempts(),
        ));

        Log::error('CrawlProductDetailsJob permanently failed', [
            'product_listing_id' => $this->productListingId,
            'crawler' => $this->crawlerClass,
            'url' => $this->url,
            'attempt' => $this->attempts(),
            'error' => $exception->getMessage(),
            'retailer_slug' => $retailerSlug,
        ]);
    }

    /**
     * Extract the retailer slug from the crawler class name.
     */
    private function getRetailerSlugFromCrawlerClass(): string
    {
        $className = class_basename($this->crawlerClass);

        $name = str_replace('Crawler', '', $className);

        return Str::slug(Str::snake($name));
    }

    /**
     * Process and cache product images.
     *
     * @param  array<mixed>  $images
     * @return array<array{url: string, cached_url: string|null, alt_text: string|null, is_primary: bool, width: int|null, height: int|null}>
     */
    private function processImages(array $images): array
    {
        if (empty($images)) {
            return [];
        }

        $normalizedImages = array_map(function ($img) {
            if (is_object($img) && method_exists($img, 'toArray')) {
                return $img->toArray();
            }
            if (is_string($img)) {
                return ['url' => $img];
            }

            return $img;
        }, $images);

        $imageCacheService = app(ImageCacheService::class);

        return $imageCacheService->cacheImages($normalizedImages);
    }
}
