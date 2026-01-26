<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Scrapers\TescoCrawler;
use App\Jobs\Crawler\CrawlProductListingsJob;
use Illuminate\Console\Command;

class CrawlTescoCommand extends Command
{
    protected $signature = 'crawler:tesco
                            {--queue= : The queue to dispatch jobs to}
                            {--sync : Run synchronously instead of queuing}';

    protected $description = 'Crawl Tesco product listings for dog food and treats';

    public function handle(): int
    {
        $this->info('Starting Tesco product listing crawler...');

        $crawler = new TescoCrawler(new GuzzleHttpAdapter);
        $startingUrls = $crawler->getStartingUrls();

        $this->info('Found '.count($startingUrls).' starting URLs to crawl');

        foreach ($startingUrls as $url) {
            $this->line("Dispatching crawl job for: {$url}");

            $job = new CrawlProductListingsJob(
                crawlerClass: TescoCrawler::class,
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
        }

        $this->info('All crawl jobs have been dispatched!');
        $this->line('Monitor the logs to see progress: php artisan pail');

        return self::SUCCESS;
    }
}
