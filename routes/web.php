<?php

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

require __DIR__.'/settings.php';
