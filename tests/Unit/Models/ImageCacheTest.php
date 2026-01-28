<?php

declare(strict_types=1);

use App\Models\ImageCache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

describe('ImageCache model', function () {
    it('can be created with factory', function () {
        $cache = ImageCache::factory()->create();

        expect($cache)->toBeInstanceOf(ImageCache::class)
            ->and($cache->original_url)->not->toBeNull()
            ->and($cache->cached_path)->not->toBeNull()
            ->and($cache->disk)->toBe('public');
    });

    it('casts attributes correctly', function () {
        $cache = ImageCache::factory()->create([
            'file_size_bytes' => 12345,
            'width' => 800,
            'height' => 600,
            'fetch_count' => 5,
        ]);

        expect($cache->file_size_bytes)->toBeInt()
            ->and($cache->width)->toBeInt()
            ->and($cache->height)->toBeInt()
            ->and($cache->fetch_count)->toBeInt()
            ->and($cache->last_fetched_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });
});

describe('getCachedUrlAttribute', function () {
    it('returns the URL for the cached image', function () {
        $cache = ImageCache::factory()->create([
            'cached_path' => 'images/cached/test-image.jpg',
            'disk' => 'public',
        ]);

        $cachedUrl = $cache->cached_url;

        expect($cachedUrl)->toContain('images/cached/test-image.jpg');
    });

    it('returns null when cached_path is empty', function () {
        $cache = ImageCache::factory()->create([
            'cached_path' => '',
        ]);

        expect($cache->cached_url)->toBeNull();
    });
});

describe('incrementFetchCount', function () {
    it('increments the fetch count', function () {
        $cache = ImageCache::factory()->create([
            'fetch_count' => 5,
        ]);

        $cache->incrementFetchCount();
        $cache->refresh();

        expect($cache->fetch_count)->toBe(6);
    });

    it('updates last_fetched_at timestamp', function () {
        $oldTimestamp = now()->subDay();

        $cache = ImageCache::factory()->create([
            'last_fetched_at' => $oldTimestamp,
        ]);

        $this->travelTo(now()->addHour());

        $cache->incrementFetchCount();
        $cache->refresh();

        expect($cache->last_fetched_at->isAfter($oldTimestamp))->toBeTrue();
    });
});

describe('fileExists', function () {
    it('returns true when file exists', function () {
        $cache = ImageCache::factory()->create([
            'cached_path' => 'images/cached/existing-file.jpg',
            'disk' => 'public',
        ]);

        Storage::disk('public')->put('images/cached/existing-file.jpg', 'image data');

        expect($cache->fileExists())->toBeTrue();
    });

    it('returns false when file does not exist', function () {
        $cache = ImageCache::factory()->create([
            'cached_path' => 'images/cached/non-existing-file.jpg',
            'disk' => 'public',
        ]);

        expect($cache->fileExists())->toBeFalse();
    });
});

describe('deleteFile', function () {
    it('deletes the cached file', function () {
        $cache = ImageCache::factory()->create([
            'cached_path' => 'images/cached/to-delete.jpg',
            'disk' => 'public',
        ]);

        Storage::disk('public')->put('images/cached/to-delete.jpg', 'image data');
        Storage::disk('public')->assertExists('images/cached/to-delete.jpg');

        $result = $cache->deleteFile();

        expect($result)->toBeTrue();
        Storage::disk('public')->assertMissing('images/cached/to-delete.jpg');
    });

    it('returns true when file does not exist', function () {
        $cache = ImageCache::factory()->create([
            'cached_path' => 'images/cached/non-existing.jpg',
            'disk' => 'public',
        ]);

        $result = $cache->deleteFile();

        expect($result)->toBeTrue();
    });
});

describe('factory states', function () {
    it('can create orphaned images', function () {
        $cache = ImageCache::factory()->orphaned()->create();

        expect($cache->last_fetched_at->isBefore(now()->subDays(30)))->toBeTrue()
            ->and($cache->fetch_count)->toBe(0);
    });

    it('can create recently fetched images', function () {
        $cache = ImageCache::factory()->recentlyFetched()->create();

        expect($cache->last_fetched_at->isAfter(now()->subHours(2)))->toBeTrue();
    });
});
