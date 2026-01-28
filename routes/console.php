<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Crawler Scheduling
|--------------------------------------------------------------------------
|
| Daily crawls for product listings run at off-peak hours (2-5 AM UK time).
| The schedule uses withoutOverlapping() to prevent concurrent crawls and
| onOneServer() for distributed deployments on Laravel Cloud.
|
*/

Schedule::command('crawler:dispatch-all --delay=300')
    ->dailyAt('02:00')
    ->timezone('Europe/London')
    ->withoutOverlapping(expiresAt: 180)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/crawler-schedule.log'))
    ->description('Daily product listing crawl for all active retailers');

/*
|--------------------------------------------------------------------------
| Review Scraping (Weekly)
|--------------------------------------------------------------------------
|
| Reviews don't change frequently, so we only scrape them weekly.
| This runs on Sunday nights at 3 AM UK time to avoid peak hours.
|
| TODO: Enable once CrawlProductReviewsCommand is implemented
|
*/

// Schedule::command('crawler:reviews')
//     ->weeklyOn(Schedule::SUNDAY, '03:00')
//     ->timezone('Europe/London')
//     ->withoutOverlapping(expiresAt: 240)
//     ->onOneServer()
//     ->appendOutputTo(storage_path('logs/crawler-reviews.log'))
//     ->description('Weekly product review scraping');

/*
|--------------------------------------------------------------------------
| Image Cleanup (Daily)
|--------------------------------------------------------------------------
|
| Cleanup orphaned images that haven't been accessed in 30+ days.
| Runs daily at 4 AM UK time to avoid peak hours and after crawlers.
|
*/

Schedule::job(new \App\Jobs\CleanupOrphanedImagesJob(daysOld: 30))
    ->dailyAt('04:00')
    ->timezone('Europe/London')
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/image-cleanup.log'))
    ->description('Daily cleanup of orphaned images not accessed in 30+ days');
