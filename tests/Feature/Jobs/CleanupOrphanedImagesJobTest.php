<?php

declare(strict_types=1);

use App\Jobs\CleanupOrphanedImagesJob;
use App\Models\ImageCache;
use App\Services\ImageCacheService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

describe('CleanupOrphanedImagesJob', function () {
    it('can be dispatched and serialized', function () {
        Queue::fake();

        CleanupOrphanedImagesJob::dispatch(30);

        Queue::assertPushed(CleanupOrphanedImagesJob::class, function ($job) {
            return $job->daysOld === 30;
        });
    });

    it('has correct tags for monitoring', function () {
        $job = new CleanupOrphanedImagesJob(30);

        $tags = $job->tags();

        expect($tags)->toContain('image-cache')
            ->toContain('cleanup');
    });

    it('uses default days value when not specified', function () {
        $job = new CleanupOrphanedImagesJob;

        expect($job->daysOld)->toBe(30);
    });

    it('accepts custom days value', function () {
        $job = new CleanupOrphanedImagesJob(60);

        expect($job->daysOld)->toBe(60);
    });

    it('cleans up orphaned images when executed', function () {
        $oldCache = ImageCache::factory()->create([
            'last_fetched_at' => now()->subDays(40),
            'cached_path' => 'images/cached/old-image.jpg',
            'disk' => 'public',
        ]);

        $recentCache = ImageCache::factory()->create([
            'last_fetched_at' => now()->subDays(5),
            'cached_path' => 'images/cached/recent-image.jpg',
            'disk' => 'public',
        ]);

        Storage::disk('public')->put($oldCache->cached_path, 'old image data');
        Storage::disk('public')->put($recentCache->cached_path, 'recent image data');

        $job = new CleanupOrphanedImagesJob(30);
        $job->handle(new ImageCacheService('public', 'images/cached'));

        expect(ImageCache::find($oldCache->id))->toBeNull();
        expect(ImageCache::find($recentCache->id))->not->toBeNull();
        Storage::disk('public')->assertMissing($oldCache->cached_path);
        Storage::disk('public')->assertExists($recentCache->cached_path);
    });

    it('has configurable timeout', function () {
        $job = new CleanupOrphanedImagesJob;

        expect($job->timeout)->toBe(300);
    });

    it('only tries once', function () {
        $job = new CleanupOrphanedImagesJob;

        expect($job->tries)->toBe(1);
    });
});
