<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Crawler\Scrapers\BMCrawler;
use App\Jobs\Crawler\CrawlProductListingsJob;
use Illuminate\Console\Command;

class CrawlBMProductListings extends Command
{
    protected $signature = 'crawler:bm
                            {--queue= : The queue to dispatch jobs to}
                            {--sync : Run synchronously instead of queuing}';

    protected $description = 'Crawl B&M product listings for dog food and treats';

    public function handle(): int
    {
        $this->info('Starting B&M product listing crawler...');

        // Get starting URLs from the B&M crawler
        $crawler = new BMCrawler(new \App\Crawler\Adapters\GuzzleHttpAdapter());
        $startingUrls = $crawler->getStartingUrls();

        $this->info("Found " . count($startingUrls) . " starting URLs to crawl");

        foreach ($startingUrls as $url) {
            $this->line("Dispatching crawl job for: {$url}");

            $job = new CrawlProductListingsJob(
                crawlerClass: BMCrawler::class,
                url: $url,
                crawlId: null,
                useAdvancedAdapter: true,
            );

            if ($this->option('sync')) {
                // Run synchronously
                $job->handle();
            } else {
                // Dispatch to queue
                $queue = $this->option('queue');
                if ($queue) {
                    $job->onQueue($queue);
                }
                dispatch($job);
            }
        }

        $this->info('All crawl jobs have been dispatched!');
        $this->line('Monitor the logs to see progress: php artisan pail');

        return self::SUCCESS;
    }
}
