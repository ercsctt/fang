<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Crawler\MatchProductListingJob;
use App\Models\ProductListing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MatchUnmatchedListings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawler:match-unmatched
        {--retailer= : Filter by retailer slug}
        {--queue=default : Queue to dispatch jobs to}
        {--sync : Run synchronously instead of dispatching jobs}
        {--limit=0 : Limit number of listings to process (0 = no limit)}
        {--no-create : Do not create new products if no match found}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Match all product listings that do not have a product match';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Finding unmatched product listings...');

        $query = ProductListing::query()
            ->whereNotNull('title')
            ->whereNotIn('id', function ($query) {
                $query->select('product_listing_id')
                    ->from('product_listing_matches');
            });

        // Filter by retailer if specified
        if ($retailerSlug = $this->option('retailer')) {
            $query->whereHas('retailer', function ($q) use ($retailerSlug) {
                $q->where('slug', $retailerSlug);
            });
        }

        // Apply limit if specified
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $listings = $query->get();
        $count = $listings->count();

        if ($count === 0) {
            $this->info('No unmatched listings found.');

            return self::SUCCESS;
        }

        $this->info("Found {$count} unmatched listings to process.");

        $sync = $this->option('sync');
        $queue = $this->option('queue');
        $createProduct = ! $this->option('no-create');

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $dispatched = 0;

        foreach ($listings as $listing) {
            if ($sync) {
                MatchProductListingJob::dispatchSync($listing->id, $createProduct);
            } else {
                MatchProductListingJob::dispatch($listing->id, $createProduct)
                    ->onQueue($queue);
            }

            $dispatched++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($sync) {
            $this->info("Processed {$dispatched} listings synchronously.");
        } else {
            $this->info("Dispatched {$dispatched} matching jobs to the '{$queue}' queue.");
        }

        Log::info('MatchUnmatchedListings command completed', [
            'total_dispatched' => $dispatched,
            'sync' => $sync,
            'queue' => $queue,
            'retailer' => $this->option('retailer'),
        ]);

        return self::SUCCESS;
    }
}
