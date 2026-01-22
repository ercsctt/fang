<?php

declare(strict_types=1);

namespace App\Crawler\Proxies;

use App\Crawler\Contracts\ProxyAdapterInterface;

/**
 * Null proxy adapter for when no proxy is needed.
 * Useful for local development or direct connections.
 */
class NullProxyAdapter implements ProxyAdapterInterface
{
    public function getProxyUrl(): ?string
    {
        return null;
    }

    public function getProxyConfig(): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function rotate(): void
    {
        // No-op: nothing to rotate
    }
}
