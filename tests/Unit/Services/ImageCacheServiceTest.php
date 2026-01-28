<?php

declare(strict_types=1);

use App\Models\ImageCache;
use App\Services\ImageCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->service = new ImageCacheService('public', 'images/cached');
});

describe('cacheImage', function () {
    it('caches an image from a valid URL', function () {
        $imageContent = file_get_contents(__DIR__.'/../../Fixtures/test-image.jpg');

        Http::fake([
            'https://example.com/image.jpg' => Http::response($imageContent, 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $result = $this->service->cacheImage('https://example.com/image.jpg');

        expect($result)->toBeInstanceOf(ImageCache::class)
            ->and($result->original_url)->toBe('https://example.com/image.jpg')
            ->and($result->disk)->toBe('public')
            ->and($result->mime_type)->toBe('image/jpeg')
            ->and($result->fetch_count)->toBe(1);

        Storage::disk('public')->assertExists($result->cached_path);
    });

    it('returns existing cache if image already cached', function () {
        $imageContent = file_get_contents(__DIR__.'/../../Fixtures/test-image.jpg');
        $url = 'https://example.com/existing-image.jpg';

        Http::fake([
            $url => Http::response($imageContent, 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $firstCache = $this->service->cacheImage($url);

        Http::assertSentCount(1);

        $secondCache = $this->service->cacheImage($url);

        Http::assertSentCount(1);

        expect($secondCache->id)->toBe($firstCache->id)
            ->and($secondCache->fetch_count)->toBe(2);
    });

    it('returns null for invalid URLs', function () {
        $result = $this->service->cacheImage('not-a-valid-url');

        expect($result)->toBeNull();
    });

    it('returns null for FTP URLs', function () {
        $result = $this->service->cacheImage('ftp://example.com/image.jpg');

        expect($result)->toBeNull();
    });

    it('returns null when image download fails', function () {
        Http::fake([
            'https://example.com/missing.jpg' => Http::response('Not Found', 404),
        ]);

        $result = $this->service->cacheImage('https://example.com/missing.jpg');

        expect($result)->toBeNull();
    });

    it('returns null for unsupported MIME types', function () {
        Http::fake([
            'https://example.com/document.pdf' => Http::response('PDF content', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $result = $this->service->cacheImage('https://example.com/document.pdf');

        expect($result)->toBeNull();
    });

    it('handles images with different MIME types', function () {
        $imageContent = file_get_contents(__DIR__.'/../../Fixtures/test-image.jpg');

        Http::fake([
            'https://example.com/image.png' => Http::response($imageContent, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $result = $this->service->cacheImage('https://example.com/image.png');

        expect($result)->toBeInstanceOf(ImageCache::class)
            ->and($result->mime_type)->toBe('image/png')
            ->and($result->cached_path)->toEndWith('.png');
    });

    it('re-caches image if file was deleted', function () {
        $imageContent = file_get_contents(__DIR__.'/../../Fixtures/test-image.jpg');
        $url = 'https://example.com/image-to-delete.jpg';

        Http::fake([
            $url => Http::response($imageContent, 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $firstCache = $this->service->cacheImage($url);
        $path = $firstCache->cached_path;

        Storage::disk('public')->delete($path);

        $secondCache = $this->service->cacheImage($url);

        expect($secondCache->id)->not->toBe($firstCache->id);
        Storage::disk('public')->assertExists($secondCache->cached_path);
    });
});

describe('cacheImages', function () {
    it('caches multiple images', function () {
        $imageContent = file_get_contents(__DIR__.'/../../Fixtures/test-image.jpg');

        Http::fake([
            'https://example.com/image1.jpg' => Http::response($imageContent, 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://example.com/image2.jpg' => Http::response($imageContent, 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $images = [
            'https://example.com/image1.jpg',
            'https://example.com/image2.jpg',
        ];

        $results = $this->service->cacheImages($images);

        expect($results)->toHaveCount(2)
            ->and($results[0]['url'])->toBe('https://example.com/image1.jpg')
            ->and($results[0]['cached_url'])->not->toBeNull()
            ->and($results[1]['url'])->toBe('https://example.com/image2.jpg')
            ->and($results[1]['cached_url'])->not->toBeNull();
    });

    it('handles array-formatted images with metadata', function () {
        $imageContent = file_get_contents(__DIR__.'/../../Fixtures/test-image.jpg');

        Http::fake([
            'https://example.com/image.jpg' => Http::response($imageContent, 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $images = [
            [
                'url' => 'https://example.com/image.jpg',
                'alt_text' => 'Product image',
                'is_primary' => true,
                'width' => 800,
                'height' => 600,
            ],
        ];

        $results = $this->service->cacheImages($images);

        expect($results)->toHaveCount(1)
            ->and($results[0]['url'])->toBe('https://example.com/image.jpg')
            ->and($results[0]['alt_text'])->toBe('Product image')
            ->and($results[0]['is_primary'])->toBeTrue();
    });

    it('handles mixed success and failure', function () {
        $imageContent = file_get_contents(__DIR__.'/../../Fixtures/test-image.jpg');

        Http::fake([
            'https://example.com/good-image.jpg' => Http::response($imageContent, 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://example.com/bad-image.jpg' => Http::response('Not Found', 404),
        ]);

        $images = [
            'https://example.com/good-image.jpg',
            'https://example.com/bad-image.jpg',
        ];

        $results = $this->service->cacheImages($images);

        expect($results)->toHaveCount(2)
            ->and($results[0]['cached_url'])->not->toBeNull()
            ->and($results[1]['cached_url'])->toBeNull();
    });

    it('skips images without URLs', function () {
        $images = [
            ['alt_text' => 'No URL here'],
            null,
        ];

        $results = $this->service->cacheImages($images);

        expect($results)->toHaveCount(0);
    });
});

describe('cleanupOrphanedImages', function () {
    it('removes images older than specified days', function () {
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

        $deletedCount = $this->service->cleanupOrphanedImages(30);

        expect($deletedCount)->toBe(1);
        expect(ImageCache::find($oldCache->id))->toBeNull();
        expect(ImageCache::find($recentCache->id))->not->toBeNull();
        Storage::disk('public')->assertMissing($oldCache->cached_path);
        Storage::disk('public')->assertExists($recentCache->cached_path);
    });

    it('removes images with null last_fetched_at', function () {
        $nullDateCache = ImageCache::factory()->create([
            'last_fetched_at' => null,
            'cached_path' => 'images/cached/null-date-image.jpg',
            'disk' => 'public',
        ]);

        Storage::disk('public')->put($nullDateCache->cached_path, 'image data');

        $deletedCount = $this->service->cleanupOrphanedImages(30);

        expect($deletedCount)->toBe(1);
        expect(ImageCache::find($nullDateCache->id))->toBeNull();
    });
});

describe('getStatistics', function () {
    it('returns cache statistics', function () {
        ImageCache::factory()->count(3)->create([
            'file_size_bytes' => 10000,
        ]);

        $stats = $this->service->getStatistics();

        expect($stats['total_images'])->toBe(3)
            ->and($stats['total_size_bytes'])->toBe(30000)
            ->and($stats['disk'])->toBe('public');
    });

    it('returns zero stats for empty cache', function () {
        $stats = $this->service->getStatistics();

        expect($stats['total_images'])->toBe(0)
            ->and($stats['total_size_bytes'])->toBe(0);
    });
});

describe('getPlaceholderUrl', function () {
    it('returns placeholder URL', function () {
        $url = $this->service->getPlaceholderUrl();

        expect($url)->toContain('/images/placeholder.svg');
    });
});
