<?php

declare(strict_types=1);

namespace App\Crawler\Examples;

use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Contracts\SupportsProxyInterface;
use App\Crawler\Proxies\BrightDataProxyAdapter;
use App\Crawler\Proxies\NullProxyAdapter;
use App\Crawler\Proxies\ProxyManager;

/**
 * Examples demonstrating the new proxy provider system.
 * This file is for documentation purposes only.
 */
class ProxyUsageExample
{
    /**
     * Example 1: Using GuzzleHttpAdapter with a single proxy provider.
     */
    public function exampleGuzzleWithBrightData(): string
    {
        // Create a proxy provider
        $proxyProvider = new BrightDataProxyAdapter(
            username: 'your-username',
            password: 'your-password',
            country: 'gb',
        );

        // Create an HTTP adapter that supports proxies
        $adapter = new GuzzleHttpAdapter();
        $adapter->withProxy($proxyProvider);

        // Use the adapter
        return $adapter->fetchHtml('https://example.com');
    }

    /**
     * Example 2: Using GuzzleHttpAdapter with advanced features enabled.
     */
    public function exampleAdvancedFeaturesWithProxy(): string
    {
        $proxyProvider = new BrightDataProxyAdapter();

        $adapter = new GuzzleHttpAdapter(
            rotateUserAgent: true,
            rotateProxy: true,
        );
        $adapter->withProxy($proxyProvider);

        return $adapter->fetchHtml('https://example.com');
    }

    /**
     * Example 3: Disable rotation for simple sites.
     */
    public function exampleWithoutRotation(): string
    {
        $adapter = new GuzzleHttpAdapter(
            rotateUserAgent: false,
            rotateProxy: false,
        );

        // Still provides anti-bot headers, just without rotation
        return $adapter->fetchHtml('https://example.com');
    }

    /**
     * Example 4: Using ProxyManager for multiple proxy providers.
     * Useful for load balancing or failover between providers.
     */
    public function exampleProxyManager(): string
    {
        // Create multiple proxy providers
        $brightData = new BrightDataProxyAdapter(
            username: 'brightdata-user',
            password: 'brightdata-pass',
        );

        // If you had other providers:
        // $oxylabs = new OxylabsProxyAdapter(...);
        // $smartProxy = new SmartProxyAdapter(...);

        // Create a proxy manager that rotates between providers
        $proxyManager = new ProxyManager([
            $brightData,
            // $oxylabs,
            // $smartProxy,
        ]);

        // Use with any adapter that supports proxies
        $adapter = new GuzzleHttpAdapter();
        $adapter->withProxy($proxyManager);

        return $adapter->fetchHtml('https://example.com');
    }

    /**
     * Example 5: Checking if an adapter supports proxies.
     * Useful for generic code that works with different adapters.
     */
    public function exampleCheckProxySupport($adapter): void
    {
        if ($adapter instanceof SupportsProxyInterface) {
            // This adapter can use external proxies
            $proxyProvider = new BrightDataProxyAdapter();
            $adapter->withProxy($proxyProvider);
        }

        $adapter->fetchHtml('https://example.com');
    }

    /**
     * Example 6: No proxy (direct connection).
     */
    public function exampleNoProxy(): string
    {
        $adapter = new GuzzleHttpAdapter();

        // Option 1: Don't configure any proxy
        // Option 2: Use NullProxyAdapter explicitly
        $adapter->withProxy(new NullProxyAdapter());

        return $adapter->fetchHtml('https://example.com');
    }

    /**
     * Example 7: Factory pattern for creating adapters.
     */
    public function createAdapterWithProxy(string $type, ?BrightDataProxyAdapter $proxy = null)
    {
        $adapter = match ($type) {
            'basic' => new GuzzleHttpAdapter(rotateUserAgent: false, rotateProxy: false),
            'production' => new GuzzleHttpAdapter(rotateUserAgent: true, rotateProxy: true),
            default => throw new \InvalidArgumentException("Unknown adapter type: {$type}"),
        };

        // Configure proxy if provided
        if ($proxy) {
            $adapter->withProxy($proxy);
        }

        return $adapter;
    }
}
