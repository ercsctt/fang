<?php

use App\Http\Controllers\Admin\CrawlMonitoringController;
use App\Http\Controllers\Admin\ProductVerificationController;
use App\Http\Controllers\Admin\RetailerController;
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
    Route::prefix('retailers')->group(function () {
        Route::get('/', [RetailerController::class, 'index'])
            ->name('admin.retailers.index');
        Route::get('/create', [RetailerController::class, 'create'])
            ->name('admin.retailers.create');
        Route::post('/', [RetailerController::class, 'store'])
            ->name('admin.retailers.store');
        Route::get('/{retailer}/edit', [RetailerController::class, 'edit'])
            ->name('admin.retailers.edit');
        Route::put('/{retailer}', [RetailerController::class, 'update'])
            ->name('admin.retailers.update');
        Route::post('/{retailer}/test-connection', [RetailerController::class, 'testConnection'])
            ->name('admin.retailers.test-connection');
    });

    Route::get('/crawl-monitoring', [CrawlMonitoringController::class, 'index'])
        ->name('admin.crawl-monitoring');
    Route::post('/crawl-monitoring/jobs/{job}/retry', [CrawlMonitoringController::class, 'retryJob'])
        ->name('admin.crawl-monitoring.jobs.retry');
    Route::delete('/crawl-monitoring/jobs/{job}', [CrawlMonitoringController::class, 'deleteJob'])
        ->name('admin.crawl-monitoring.jobs.delete');
    Route::post('/crawl-monitoring/jobs/retry-all', [CrawlMonitoringController::class, 'retryAllJobs'])
        ->name('admin.crawl-monitoring.jobs.retry-all');

    Route::prefix('product-verification')->group(function () {
        Route::get('/', [ProductVerificationController::class, 'index'])
            ->name('admin.product-verification.index');
        Route::get('/stats', [ProductVerificationController::class, 'stats'])
            ->name('admin.product-verification.stats');
        Route::get('/{match}', [ProductVerificationController::class, 'show'])
            ->name('admin.product-verification.show');
        Route::post('/{match}/approve', [ProductVerificationController::class, 'approve'])
            ->name('admin.product-verification.approve');
        Route::post('/{match}/reject', [ProductVerificationController::class, 'reject'])
            ->name('admin.product-verification.reject');
        Route::post('/{match}/rematch', [ProductVerificationController::class, 'rematch'])
            ->name('admin.product-verification.rematch');
        Route::post('/bulk-approve', [ProductVerificationController::class, 'bulkApprove'])
            ->name('admin.product-verification.bulk-approve');
    });
});

require __DIR__.'/settings.php';
