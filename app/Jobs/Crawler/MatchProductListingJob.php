<?php

declare(strict_types=1);

namespace App\Jobs\Crawler;

use App\Models\ProductListing;
use App\Services\ProductMatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MatchProductListingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        private readonly int $productListingId,
        private readonly bool $createProductIfNoMatch = true,
    ) {}

    public function handle(ProductMatcher $matcher): void
    {
        Log::info('Starting product matching job', [
            'product_listing_id' => $this->productListingId,
        ]);

        $listing = ProductListing::query()->find($this->productListingId);

        if (! $listing) {
            Log::error('Product listing not found for matching', [
                'product_listing_id' => $this->productListingId,
            ]);

            return;
        }

        // Skip if listing doesn't have enough data for matching
        if ($listing->title === null || $listing->title === '') {
            Log::warning('Product listing has no title, skipping match', [
                'product_listing_id' => $this->productListingId,
            ]);

            return;
        }

        try {
            $match = $matcher->match($listing, $this->createProductIfNoMatch);

            if ($match === null) {
                Log::info('No suitable match found for product listing', [
                    'product_listing_id' => $this->productListingId,
                    'title' => $listing->title,
                ]);

                return;
            }

            Log::info('Product matching completed', [
                'product_listing_id' => $this->productListingId,
                'product_id' => $match->product_id,
                'confidence_score' => $match->confidence_score,
                'match_type' => $match->match_type->value,
            ]);
        } catch (\Exception $e) {
            Log::error('Product matching job failed', [
                'product_listing_id' => $this->productListingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the tags for monitoring this job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'product-matching',
            'listing:'.$this->productListingId,
        ];
    }
}
