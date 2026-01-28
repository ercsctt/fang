<?php

use App\Http\Controllers\Admin\RetailerStatusController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\PriceAlertController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\RetailerController;
use App\Http\Controllers\Api\V1\SearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function () {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');
    Route::get('products/{slug}/price-history', [ProductController::class, 'priceHistory'])->name('products.price-history');

    Route::get('retailers', [RetailerController::class, 'index'])->name('retailers.index');

    Route::get('search', SearchController::class)->name('search');
    Route::get('search/suggestions', [SearchController::class, 'suggestions'])->name('search.suggestions');
    Route::get('search/filters', [SearchController::class, 'filters'])->name('search.filters');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('alerts', [PriceAlertController::class, 'index'])->name('alerts.index');
        Route::post('alerts', [PriceAlertController::class, 'store'])->name('alerts.store');
        Route::delete('alerts/{alert}', [PriceAlertController::class, 'destroy'])->name('alerts.destroy');

        Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
        Route::post('exports', [ExportController::class, 'store'])->name('exports.store');
        Route::get('exports/{export}', [ExportController::class, 'show'])->name('exports.show');
        Route::get('exports/{export}/download', [ExportController::class, 'download'])->name('exports.download');
        Route::delete('exports/{export}', [ExportController::class, 'destroy'])->name('exports.destroy');

        Route::prefix('admin')->name('admin.')->group(function () {
            Route::prefix('retailers/{retailer}')->name('retailers.')->group(function () {
                Route::post('pause', [RetailerStatusController::class, 'pause'])->name('pause');
                Route::post('resume', [RetailerStatusController::class, 'resume'])->name('resume');
                Route::post('disable', [RetailerStatusController::class, 'disable'])->name('disable');
                Route::post('enable', [RetailerStatusController::class, 'enable'])->name('enable');
            });
        });
    });
});
