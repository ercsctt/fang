<?php

declare(strict_types=1);

namespace App\Domain\Crawler\Projectors;

use App\Domain\Crawler\Events\ProductListingDiscovered;
use App\Jobs\Crawler\CrawlProductDetailsJob;
use App\Models\ProductListing;
use App\Models\Retailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class ProductListingProjector extends Projector
{
    public function onProductListingDiscovered(ProductListingDiscovered $event): void
    {
        $retailer = Retailer::query()
            ->where('slug', $this->slugify($event->retailer))
            ->first();

        if (! $retailer) {
            Log::warning('Retailer not found for discovered listing', [
                'retailer' => $event->retailer,
                'url' => $event->url,
            ]);

            return;
        }

        $listing = ProductListing::updateOrCreate(
            [
                'retailer_id' => $retailer->id,
                'url' => $event->url,
            ],
            [
                'title' => $this->extractTitleFromUrl($event->url),
                'category' => $event->category,
                'last_scraped_at' => now(),
            ]
        );

        $wasRecentlyCreated = $listing->wasRecentlyCreated;

        Log::info('Product listing processed', [
            'listing_id' => $listing->id,
            'url' => $event->url,
            'was_created' => $wasRecentlyCreated,
        ]);

        if ($wasRecentlyCreated && $retailer->crawler_class) {
            CrawlProductDetailsJob::dispatch(
                crawlerClass: $retailer->crawler_class,
                productListingId: $listing->id,
                url: $event->url,
            )->onQueue('crawl-details');
        }
    }

    private function slugify(string $retailer): string
    {
        return Str::slug($retailer);
    }

    private function extractTitleFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $segments = array_filter(explode('/', $path));
        $lastSegment = end($segments) ?: 'Unknown Product';

        return ucwords(str_replace(['-', '_'], ' ', $lastSegment));
    }
}
