<?php

declare(strict_types=1);

namespace App\Crawler\Contracts;

interface ProxyAdapterInterface
{
    /**
     * Get the proxy URL to use for requests.
     *
     * @return string|null The proxy URL (e.g., "http://user:pass@proxy.example.com:8080") or null if no proxy
     */
    public function getProxyUrl(): ?string;

    /**
     * Get proxy configuration as an array for Guzzle.
     *
     * @return array<string, mixed> Proxy configuration
     */
    public function getProxyConfig(): array;

    /**
     * Check if the proxy is available and configured.
     */
    public function isAvailable(): bool;

    /**
     * Rotate to the next proxy if using a pool.
     */
    public function rotate(): void;
}
