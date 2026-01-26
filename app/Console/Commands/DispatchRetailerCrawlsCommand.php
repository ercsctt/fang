<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Scrapers\BaseCrawler;
use App\Jobs\Crawler\CrawlProductListingsJob;
use App\Models\Retailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchRetailerCrawlsCommand extends Command
{
    protected $signature = 'crawler:dispatch-all
                            {--retailer= : Only crawl a specific retailer by slug}
                            {--queue=crawler : The queue to dispatch jobs to}
                            {--delay=0 : Base delay in seconds between retailers}';

    protected $description = 'Dispatch crawl jobs for all active retailers with their starting URLs';

    public function handle(): int
    {
        $query = Retailer::active();

        if ($retailerSlug = $this->option('retailer')) {
            $query->where('slug', $retailerSlug);
        }

        $retailers = $query->get();

        if ($retailers->isEmpty()) {
            $this->warn('No active retailers found to crawl.');

            return self::SUCCESS;
        }

        $this->info("Dispatching crawl jobs for {$retailers->count()} retailer(s)...");

        $baseDelay = (int) $this->option('delay');
        $queue = $this->option('queue');
        $retailerIndex = 0;

        foreach ($retailers as $retailer) {
            if (! $retailer->crawler_class || ! class_exists($retailer->crawler_class)) {
                $this->warn("Skipping {$retailer->name}: Invalid or missing crawler_class");

                continue;
            }

            $this->line("Processing retailer: {$retailer->name}");

            try {
                $this->dispatchCrawlsForRetailer($retailer, $queue, $baseDelay * $retailerIndex);
                $retailerIndex++;

                $retailer->update(['last_crawled_at' => now()]);
            } catch (\Exception $e) {
                $this->error("Failed to dispatch crawls for {$retailer->name}: {$e->getMessage()}");
                Log::error('Failed to dispatch crawls for retailer', [
                    'retailer' => $retailer->slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info('All crawl jobs have been dispatched!');

        return self::SUCCESS;
    }

    /**
     * Dispatch crawl jobs for a single retailer.
     */
    private function dispatchCrawlsForRetailer(Retailer $retailer, ?string $queue, int $delaySeconds): void
    {
        /** @var BaseCrawler $crawler */
        $crawler = new $retailer->crawler_class(new GuzzleHttpAdapter);
        $startingUrls = $crawler->getStartingUrls();

        $this->line('  Found '.count($startingUrls)." starting URLs for {$retailer->name}");

        foreach ($startingUrls as $index => $url) {
            $job = new CrawlProductListingsJob(
                crawlerClass: $retailer->crawler_class,
                url: $url,
                crawlId: null,
                useAdvancedAdapter: true,
            );

            if ($queue) {
                $job->onQueue($queue);
            }

            $jobDelay = $delaySeconds + ($index * 5);
            if ($jobDelay > 0) {
                dispatch($job)->delay(now()->addSeconds($jobDelay));
            } else {
                dispatch($job);
            }

            $this->line("  Dispatched job for: {$url}".($jobDelay > 0 ? " (delay: {$jobDelay}s)" : ''));
        }
    }
}
