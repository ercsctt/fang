<?php

declare(strict_types=1);

namespace App\Crawler\Contracts;

/**
 * Interface for HTTP adapters that support external proxy configuration.
 * Adapters that handle proxies internally (like browser-based scraper APIs)
 * should NOT implement this interface.
 */
interface SupportsProxyInterface
{
    /**
     * Configure the adapter to use a specific proxy provider.
     */
    public function withProxy(ProxyAdapterInterface $proxyAdapter): static;

    /**
     * Get the currently configured proxy adapter, if any.
     */
    public function getProxyAdapter(): ?ProxyAdapterInterface;
}
