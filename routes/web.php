<?php

use App\Http\Controllers\Admin\CrawlMonitoringController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [ProductController::class, 'home'])->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::prefix('products')->group(function () {
    Route::get('/', [ProductController::class, 'index'])->name('products.index');
    Route::get('/search', [ProductController::class, 'search'])->name('products.search');
    Route::get('/{product:slug}', [ProductController::class, 'show'])->name('products.show');
});

Route::middleware(['auth', 'verified'])->prefix('scraper-tester')->group(function () {
    Route::get('/', [\App\Http\Controllers\ScraperTesterController::class, 'index'])->name('scraper-tester');
    Route::post('/fetch', [\App\Http\Controllers\ScraperTesterController::class, 'fetch'])->name('scraper-tester.fetch');
});

Route::middleware(['auth', 'verified'])->prefix('admin')->group(function () {
    Route::get('/crawl-monitoring', [CrawlMonitoringController::class, 'index'])
        ->name('admin.crawl-monitoring');
    Route::post('/crawl-monitoring/jobs/{job}/retry', [CrawlMonitoringController::class, 'retryJob'])
        ->name('admin.crawl-monitoring.jobs.retry');
    Route::delete('/crawl-monitoring/jobs/{job}', [CrawlMonitoringController::class, 'deleteJob'])
        ->name('admin.crawl-monitoring.jobs.delete');
    Route::post('/crawl-monitoring/jobs/retry-all', [CrawlMonitoringController::class, 'retryAllJobs'])
        ->name('admin.crawl-monitoring.jobs.retry-all');
});

require __DIR__.'/settings.php';
