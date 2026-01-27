<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Scrapers\MorrisonsCrawler;
use App\Jobs\Crawler\CrawlProductListingsJob;
use Illuminate\Console\Command;

class CrawlMorrisonsCommand extends Command
{
    protected $signature = 'crawler:morrisons
                            {--queue= : The queue to dispatch jobs to}
                            {--sync : Run synchronously instead of queuing}';

    protected $description = 'Crawl Morrisons product listings for dog food and treats';

    public function handle(): int
    {
        $this->info('Starting Morrisons product listing crawler...');
        $this->warn('Note: Morrisons may use a React-based frontend - ensure JavaScript rendering is available.');

        $crawler = new MorrisonsCrawler(new GuzzleHttpAdapter);
        $startingUrls = $crawler->getStartingUrls();

        $this->info('Found '.count($startingUrls).' starting URLs to crawl');

        foreach ($startingUrls as $url) {
            $this->line("Dispatching crawl job for: {$url}");

            $job = new CrawlProductListingsJob(
                crawlerClass: MorrisonsCrawler::class,
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
