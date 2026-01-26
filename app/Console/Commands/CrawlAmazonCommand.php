<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Scrapers\AmazonCrawler;
use App\Jobs\Crawler\CrawlProductListingsJob;
use Illuminate\Console\Command;

class CrawlAmazonCommand extends Command
{
    protected $signature = 'crawler:amazon
                            {--queue= : The queue to dispatch jobs to}
                            {--sync : Run synchronously instead of queuing}';

    protected $description = 'Crawl Amazon UK product listings for dog food and treats';

    public function handle(): int
    {
        $this->info('Starting Amazon UK product listing crawler...');
        $this->warn('Note: Amazon has aggressive anti-bot detection. Ensure BrightData Web Unlocker is configured.');

        $crawler = new AmazonCrawler(new GuzzleHttpAdapter);
        $startingUrls = $crawler->getStartingUrls();

        $this->info('Found '.count($startingUrls).' starting URLs to crawl');

        foreach ($startingUrls as $url) {
            $this->line("Dispatching crawl job for: {$url}");

            $job = new CrawlProductListingsJob(
                crawlerClass: AmazonCrawler::class,
                url: $url,
                crawlId: null,
                useAdvancedAdapter: true, // Use BrightData for Amazon
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
        }

        $this->info('All crawl jobs have been dispatched!');
        $this->line('Monitor the logs to see progress: php artisan pail');

        return self::SUCCESS;
    }
}
