<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Crawler\Extractors\BM\BMProductReviewsExtractor;
use App\Crawler\Extractors\JustForPets\JFPProductReviewsExtractor;
use App\Crawler\Extractors\Morrisons\MorrisonsProductReviewsExtractor;
use App\Crawler\Extractors\Sainsburys\SainsburysProductReviewsExtractor;
use App\Crawler\Extractors\Tesco\TescoProductReviewsExtractor;
use App\Jobs\Crawler\CrawlProductReviewsJob;
use App\Models\ProductListing;
use App\Models\Retailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CrawlProductReviewsCommand extends Command
{
    protected $signature = 'crawler:reviews
                            {--listing-id= : Crawl reviews for a specific listing by ID}
                            {--retailer= : Crawl reviews for all listings from a specific retailer by slug}
                            {--days=7 : Only crawl listings that haven\'t been scraped in this many days}
                            {--queue=crawler : The queue to dispatch jobs to}
                            {--limit=0 : Limit the number of listings to process (0 = no limit)}
                            {--sync : Run jobs synchronously instead of queuing}';

    protected $description = 'Dispatch review crawl jobs for product listings';

    /**
     * Mapping of retailer slugs to their reviews extractor classes.
     *
     * @var array<string, class-string>
     */
    private array $extractorMap = [
        'bm' => BMProductReviewsExtractor::class,
        'just-for-pets' => JFPProductReviewsExtractor::class,
        'morrisons' => MorrisonsProductReviewsExtractor::class,
        'sainsburys' => SainsburysProductReviewsExtractor::class,
        'tesco' => TescoProductReviewsExtractor::class,
    ];

    public function handle(): int
    {
        $listingId = $this->option('listing-id');
        $retailerSlug = $this->option('retailer');
        $days = (int) $this->option('days');
        $queue = $this->option('queue');
        $limit = (int) $this->option('limit');
        $sync = $this->option('sync');

        if ($listingId) {
            return $this->crawlSingleListing((int) $listingId, $sync);
        }

        return $this->crawlMultipleListings($retailerSlug, $days, $queue, $limit, $sync);
    }

    /**
     * Crawl reviews for a single listing.
     */
    private function crawlSingleListing(int $listingId, bool $sync): int
    {
        $listing = ProductListing::with('retailer')->find($listingId);

        if (! $listing) {
            $this->error("Product listing #{$listingId} not found.");

            return self::FAILURE;
        }

        $extractorClass = $this->getExtractorForRetailer($listing->retailer);

        if (! $extractorClass) {
            $this->error("No reviews extractor available for retailer: {$listing->retailer->name}");

            return self::FAILURE;
        }

        $this->info("Dispatching review crawl for listing #{$listingId}: {$listing->title}");

        $job = new CrawlProductReviewsJob(
            extractorClass: $extractorClass,
            productListingId: $listing->id,
            url: $listing->url,
        );

        if ($sync) {
            $job->handle();
            $this->info('Review crawl completed synchronously.');
        } else {
            dispatch($job)->onQueue($this->option('queue'));
            $this->info('Review crawl job dispatched.');
        }

        return self::SUCCESS;
    }

    /**
     * Crawl reviews for multiple listings.
     */
    private function crawlMultipleListings(
        ?string $retailerSlug,
        int $days,
        ?string $queue,
        int $limit,
        bool $sync,
    ): int {
        $retailer = null;

        if ($retailerSlug) {
            $retailer = Retailer::where('slug', $retailerSlug)->first();

            if (! $retailer) {
                $this->error("Retailer '{$retailerSlug}' not found.");

                return self::FAILURE;
            }

            $extractorClass = $this->getExtractorForRetailer($retailer);

            if (! $extractorClass) {
                $this->error("No reviews extractor available for retailer: {$retailer->name}");

                return self::FAILURE;
            }

            $this->info("Crawling reviews for retailer: {$retailer->name}");
        } else {
            $this->info('Crawling reviews for all retailers with available extractors');
        }

        $query = ProductListing::query()
            ->with('retailer')
            ->needsReviewScraping($days);

        if ($retailer) {
            $query->where('retailer_id', $retailer->id);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $listings = $query->get();

        if ($listings->isEmpty()) {
            $this->warn('No listings need review scraping.');

            return self::SUCCESS;
        }

        $this->info("Found {$listings->count()} listing(s) needing review scraping.");

        $dispatched = 0;
        $skipped = 0;

        foreach ($listings as $listing) {
            $extractorClass = $this->getExtractorForRetailer($listing->retailer);

            if (! $extractorClass) {
                $this->line("  Skipping listing #{$listing->id}: No extractor for {$listing->retailer->name}");
                $skipped++;

                continue;
            }

            $job = new CrawlProductReviewsJob(
                extractorClass: $extractorClass,
                productListingId: $listing->id,
                url: $listing->url,
            );

            if ($sync) {
                try {
                    $job->handle();
                    $this->line("  Processed: #{$listing->id} - {$listing->title}");
                } catch (\Exception $e) {
                    $this->error("  Failed: #{$listing->id} - {$e->getMessage()}");
                    Log::error('Review crawl failed in sync mode', [
                        'listing_id' => $listing->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                dispatch($job)->onQueue($queue);
                $this->line("  Dispatched: #{$listing->id} - {$listing->title}");
            }

            $dispatched++;
        }

        $this->newLine();
        $this->info("Dispatched: {$dispatched}, Skipped: {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Get the reviews extractor class for a retailer.
     *
     * @return class-string|null
     */
    private function getExtractorForRetailer(Retailer $retailer): ?string
    {
        return $this->extractorMap[$retailer->slug] ?? null;
    }
}
