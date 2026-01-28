<?php

declare(strict_types=1);

namespace App\Jobs\Crawler;

use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\DTOs\ProductReview;
use App\Crawler\Proxies\BrightDataProxyAdapter;
use App\Models\ProductListing;
use App\Models\ProductListingReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CrawlProductReviewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly string $extractorClass,
        private readonly int $productListingId,
        private readonly string $url,
    ) {}

    public function handle(): void
    {
        Log::info('Starting product reviews crawl job', [
            'product_listing_id' => $this->productListingId,
            'extractor' => $this->extractorClass,
            'url' => $this->url,
        ]);

        $listing = ProductListing::find($this->productListingId);

        if (! $listing) {
            Log::error('Product listing not found for review crawl', [
                'product_listing_id' => $this->productListingId,
            ]);

            return;
        }

        $httpAdapter = $this->createHttpAdapter();

        /** @var ExtractorInterface $extractor */
        $extractor = new $this->extractorClass;

        $startTime = microtime(true);
        $reviewsExtracted = 0;
        $reviewsCreated = 0;
        $reviewsUpdated = 0;

        try {
            $html = $httpAdapter->get($this->url);

            foreach ($extractor->extract($html, $this->url) as $dto) {
                if ($dto instanceof ProductReview) {
                    $reviewsExtracted++;
                    $result = $this->upsertReview($listing, $dto);

                    if ($result === 'created') {
                        $reviewsCreated++;
                    } elseif ($result === 'updated') {
                        $reviewsUpdated++;
                    }
                }
            }

            $duration = microtime(true) - $startTime;

            // Update last reviews scraped timestamp
            $listing->update(['last_reviews_scraped_at' => now()]);

            Log::info('Product reviews crawl job completed', [
                'product_listing_id' => $this->productListingId,
                'url' => $this->url,
                'reviews_extracted' => $reviewsExtracted,
                'reviews_created' => $reviewsCreated,
                'reviews_updated' => $reviewsUpdated,
                'duration' => round($duration, 2).'s',
            ]);
        } catch (\Exception $e) {
            Log::error('Product reviews crawl job failed', [
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

        if (config('services.brightdata.username') && config('services.brightdata.password')) {
            $proxyAdapter = new BrightDataProxyAdapter;
            $adapter->withProxy($proxyAdapter);

            Log::info('Using BrightData proxy for product reviews crawl', [
                'zone' => config('services.brightdata.zone'),
                'country' => config('services.brightdata.country'),
            ]);
        } else {
            Log::warning('BrightData credentials not configured, crawling reviews without proxy');
        }

        return $adapter;
    }

    /**
     * Create or update a review record.
     *
     * @return string 'created', 'updated', or 'unchanged'
     */
    private function upsertReview(ProductListing $listing, ProductReview $dto): string
    {
        $existingReview = ProductListingReview::query()
            ->where('product_listing_id', $listing->id)
            ->where('external_id', $dto->externalId)
            ->first();

        $reviewData = [
            'author' => $dto->author,
            'rating' => $dto->rating,
            'title' => $dto->title,
            'body' => $dto->body,
            'verified_purchase' => $dto->verifiedPurchase,
            'review_date' => $dto->reviewDate,
            'helpful_count' => $dto->helpfulCount,
            'metadata' => $dto->metadata,
        ];

        if ($existingReview) {
            // Check if anything changed
            $hasChanges = $existingReview->body !== $dto->body
                || $existingReview->rating !== $dto->rating
                || $existingReview->helpful_count !== $dto->helpfulCount;

            if ($hasChanges) {
                $existingReview->update($reviewData);

                return 'updated';
            }

            return 'unchanged';
        }

        $listing->reviews()->create([
            'external_id' => $dto->externalId,
            ...$reviewData,
        ]);

        return 'created';
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
            'product-reviews',
            'listing:'.$this->productListingId,
        ];
    }
}
