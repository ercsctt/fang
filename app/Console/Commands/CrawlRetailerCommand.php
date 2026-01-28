<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Scrapers\BaseCrawler;
use App\Jobs\Crawler\CrawlProductListingsJob;
use App\Models\Retailer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class CrawlRetailerCommand extends Command
{
    protected $signature = 'crawler:run
                            {retailer?* : The retailer slug(s) to crawl (e.g., tesco, amazon-uk)}
                            {--all : Crawl all active retailers}
                            {--queue= : The queue to dispatch jobs to}
                            {--sync : Run synchronously instead of queuing}';

    protected $description = 'Crawl product listings for one or more retailers';

    public function handle(): int
    {
        $retailers = $this->getRetailersToCrawl();

        if ($retailers->isEmpty()) {
            $this->error('No retailers found to crawl.');
            $this->line('');
            $this->line('Usage:');
            $this->line('  php artisan crawler:run tesco           # Crawl a single retailer');
            $this->line('  php artisan crawler:run tesco asda      # Crawl multiple retailers');
            $this->line('  php artisan crawler:run --all           # Crawl all active retailers');
            $this->line('');
            $this->listAvailableRetailers();

            return self::FAILURE;
        }

        $totalJobs = 0;

        foreach ($retailers as $retailer) {
            $totalJobs += $this->crawlRetailer($retailer);
        }

        $this->newLine();
        $this->info("Dispatched {$totalJobs} crawl job(s) for {$retailers->count()} retailer(s)!");
        $this->line('Monitor the logs to see progress: php artisan pail');

        return self::SUCCESS;
    }

    /**
     * Get the retailers to crawl based on command arguments.
     *
     * @return Collection<int, Retailer>
     */
    private function getRetailersToCrawl(): Collection
    {
        if ($this->option('all')) {
            return Retailer::active()
                ->where(function ($q) {
                    $q->where('paused_until', '<', now())
                        ->orWhereNull('paused_until');
                })
                ->whereNotNull('crawler_class')
                ->get();
        }

        /** @var array<string> $slugs */
        $slugs = $this->argument('retailer');

        if (empty($slugs)) {
            return new Collection;
        }

        return Retailer::whereIn('slug', $slugs)->get();
    }

    /**
     * Crawl a single retailer and return the number of jobs dispatched.
     */
    private function crawlRetailer(Retailer $retailer): int
    {
        if (! $retailer->crawler_class || ! class_exists($retailer->crawler_class)) {
            $this->warn("Skipping {$retailer->name}: Invalid or missing crawler_class");

            return 0;
        }

        if (! $retailer->isAvailableForCrawling()) {
            if ($retailer->isPaused()) {
                $this->warn("Skipping {$retailer->name}: Retailer is paused until {$retailer->paused_until->format('Y-m-d H:i')}");
            } else {
                $this->warn("Skipping {$retailer->name}: Retailer is {$retailer->status->label()}");
            }

            return 0;
        }

        $this->info("Starting {$retailer->name} product listing crawler...");

        /** @var BaseCrawler $crawler */
        $crawler = new $retailer->crawler_class(new GuzzleHttpAdapter);
        $startingUrls = $crawler->getStartingUrls();

        $this->info('Found '.count($startingUrls).' starting URLs to crawl');

        $jobCount = 0;

        foreach ($startingUrls as $url) {
            $this->line("Dispatching crawl job for: {$url}");

            $job = new CrawlProductListingsJob(
                crawlerClass: $retailer->crawler_class,
                url: $url,
                crawlId: null,
                useAdvancedAdapter: true,
            );

            if ($this->option('sync')) {
                $job->handle();
            } else {
                $queue = $this->option('queue');
                if ($queue) {
                    $job->onQueue($queue);
                }
                dispatch($job);
            }

            $jobCount++;
        }

        $retailer->update(['last_crawled_at' => now()]);

        return $jobCount;
    }

    /**
     * List all available retailers with their slugs.
     */
    private function listAvailableRetailers(): void
    {
        $retailers = Retailer::query()
            ->select(['slug', 'name', 'status'])
            ->whereNotNull('crawler_class')
            ->orderBy('name')
            ->get();

        if ($retailers->isEmpty()) {
            $this->warn('No retailers with crawlers configured in the database.');

            return;
        }

        $this->line('Available retailers:');

        foreach ($retailers as $retailer) {
            $color = $retailer->status->isAvailableForCrawling() ? 'green' : 'yellow';
            $this->line("  - {$retailer->slug} ({$retailer->name}) [<fg={$color}>{$retailer->status->label()}</>]");
        }
    }
}
