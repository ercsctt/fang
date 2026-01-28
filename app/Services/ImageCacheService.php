<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ImageCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageCacheService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private string $disk = 'public',
        private string $directory = 'images/cached',
    ) {}

    /**
     * Cache an image from a remote URL.
     *
     * Returns the ImageCache model if successful, null otherwise.
     */
    public function cacheImage(string $url): ?ImageCache
    {
        if (! $this->isValidImageUrl($url)) {
            Log::warning('Invalid image URL provided for caching', ['url' => $url]);

            return null;
        }

        $existingCache = ImageCache::query()
            ->where('original_url', $url)
            ->first();

        if ($existingCache) {
            if ($existingCache->fileExists()) {
                $existingCache->incrementFetchCount();

                return $existingCache;
            }

            $existingCache->delete();
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept' => 'image/*',
                ])
                ->get($url);

            if (! $response->successful()) {
                Log::warning('Failed to download image', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $contentType = $response->header('Content-Type');
            $mimeType = $this->parseMimeType($contentType);

            if (! $this->isAllowedMimeType($mimeType)) {
                Log::warning('Image has unsupported MIME type', [
                    'url' => $url,
                    'mime_type' => $mimeType,
                ]);

                return null;
            }

            $imageData = $response->body();
            $extension = $this->getExtensionFromMimeType($mimeType);
            $hash = hash('sha256', $url);
            $fileName = "{$hash}.{$extension}";
            $path = "{$this->directory}/{$fileName}";

            Storage::disk($this->disk)->put($path, $imageData);

            $dimensions = $this->getImageDimensions($imageData);

            $imageCache = ImageCache::create([
                'original_url' => $url,
                'cached_path' => $path,
                'disk' => $this->disk,
                'mime_type' => $mimeType,
                'file_size_bytes' => strlen($imageData),
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'last_fetched_at' => now(),
                'fetch_count' => 1,
            ]);

            Log::info('Image cached successfully', [
                'url' => $url,
                'cached_path' => $path,
                'file_size' => strlen($imageData),
            ]);

            return $imageCache;
        } catch (\Exception $e) {
            Log::error('Failed to cache image', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cache multiple images from remote URLs.
     *
     * @param  array<string|array{url: string}>  $images
     * @return array<array{url: string, cached_url: string|null, alt_text: string|null, is_primary: bool, width: int|null, height: int|null}>
     */
    public function cacheImages(array $images): array
    {
        $cachedImages = [];

        foreach ($images as $image) {
            $url = is_array($image) ? ($image['url'] ?? null) : $image;
            $altText = is_array($image) ? ($image['alt_text'] ?? null) : null;
            $isPrimary = is_array($image) ? ($image['is_primary'] ?? false) : false;
            $originalWidth = is_array($image) ? ($image['width'] ?? null) : null;
            $originalHeight = is_array($image) ? ($image['height'] ?? null) : null;

            if (! $url) {
                continue;
            }

            $cache = $this->cacheImage($url);

            $cachedImages[] = [
                'url' => $url,
                'cached_url' => $cache?->cached_url,
                'alt_text' => $altText,
                'is_primary' => $isPrimary,
                'width' => $cache?->width ?? $originalWidth,
                'height' => $cache?->height ?? $originalHeight,
            ];
        }

        return $cachedImages;
    }

    /**
     * Get cached URL for an image, caching it first if necessary.
     */
    public function getCachedUrl(string $url): ?string
    {
        $cache = $this->cacheImage($url);

        return $cache?->cached_url;
    }

    /**
     * Get placeholder URL for lazy loading.
     */
    public function getPlaceholderUrl(): string
    {
        return config('app.url').'/images/placeholder.svg';
    }

    /**
     * Clean up orphaned cached images (not referenced for specified days).
     */
    public function cleanupOrphanedImages(int $daysOld = 30): int
    {
        $cutoffDate = now()->subDays($daysOld);

        $orphanedCaches = ImageCache::query()
            ->where('last_fetched_at', '<', $cutoffDate)
            ->orWhereNull('last_fetched_at')
            ->get();

        $deletedCount = 0;

        foreach ($orphanedCaches as $cache) {
            $cache->deleteFile();
            $cache->delete();
            $deletedCount++;
        }

        Log::info('Cleaned up orphaned cached images', [
            'deleted_count' => $deletedCount,
            'cutoff_date' => $cutoffDate->toIso8601String(),
        ]);

        return $deletedCount;
    }

    /**
     * Get statistics about the image cache.
     *
     * @return array{total_images: int, total_size_bytes: int, disk: string}
     */
    public function getStatistics(): array
    {
        return [
            'total_images' => ImageCache::count(),
            'total_size_bytes' => (int) ImageCache::sum('file_size_bytes'),
            'disk' => $this->disk,
        ];
    }

    private function isValidImageUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = parse_url($url);
        if (! isset($parsed['scheme']) || ! in_array($parsed['scheme'], ['http', 'https'], true)) {
            return false;
        }

        return true;
    }

    private function parseMimeType(?string $contentType): string
    {
        if (! $contentType) {
            return 'application/octet-stream';
        }

        $parts = explode(';', $contentType);

        return trim($parts[0]);
    }

    private function isAllowedMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::ALLOWED_MIME_TYPES, true);
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    private function getImageDimensions(string $imageData): array
    {
        try {
            $image = @imagecreatefromstring($imageData);
            if ($image !== false) {
                $width = imagesx($image);
                $height = imagesy($image);
                imagedestroy($image);

                return ['width' => $width, 'height' => $height];
            }
        } catch (\Exception $e) {
            Log::debug('Could not determine image dimensions', ['error' => $e->getMessage()]);
        }

        return ['width' => null, 'height' => null];
    }
}
