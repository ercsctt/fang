<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->prefix('scraper-tester')->group(function () {
    Route::get('/', [\App\Http\Controllers\ScraperTesterController::class, 'index'])->name('scraper-tester');
    Route::post('/fetch', [\App\Http\Controllers\ScraperTesterController::class, 'fetch'])->name('scraper-tester.fetch');
});

require __DIR__.'/settings.php';
