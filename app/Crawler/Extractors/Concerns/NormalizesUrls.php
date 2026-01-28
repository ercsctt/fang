<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Concerns;

trait NormalizesUrls
{
    /**
     * Normalize a URL to absolute form.
     *
     * Handles:
     * - Absolute URLs (http/https) - returned as-is
     * - Protocol-relative URLs (//) - prefixed with scheme
     * - Absolute paths (/) - combined with scheme and host
     * - Relative paths - combined with base path
     */
    protected function normalizeUrl(string $url, string $baseUrl): string
    {
        // Already absolute URL
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';

        // Protocol-relative URL
        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        // Absolute path
        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$url}";
        }

        // Relative path - combine with base path
        $path = $parsedBase['path'] ?? '';
        $basePath = substr($path, 0, strrpos($path, '/') + 1);

        return "{$scheme}://{$host}{$basePath}{$url}";
    }
}
