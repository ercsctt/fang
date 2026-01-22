<?php

declare(strict_types=1);

namespace App\Crawler\Proxies;

use App\Crawler\Contracts\ProxyAdapterInterface;

/**
 * Manages multiple proxy providers and handles rotation between them.
 * Useful for load balancing across different proxy services or falling back
 * when one provider is unavailable.
 */
class ProxyManager implements ProxyAdapterInterface
{
    /** @var array<ProxyAdapterInterface> */
    private array $providers = [];
    private int $currentIndex = 0;

    /**
     * @param array<ProxyAdapterInterface> $providers
     */
    public function __construct(array $providers = [])
    {
        $this->providers = array_values($providers);
    }

    /**
     * Add a proxy provider to the pool.
     */
    public function addProvider(ProxyAdapterInterface $provider): self
    {
        $this->providers[] = $provider;
        return $this;
    }

    /**
     * Get the current active proxy provider.
     */
    public function getCurrentProvider(): ?ProxyAdapterInterface
    {
        if (empty($this->providers)) {
            return null;
        }

        // Find the next available provider starting from current index
        $totalProviders = count($this->providers);
        for ($i = 0; $i < $totalProviders; $i++) {
            $index = ($this->currentIndex + $i) % $totalProviders;
            $provider = $this->providers[$index];

            if ($provider->isAvailable()) {
                $this->currentIndex = $index;
                return $provider;
            }
        }

        return null;
    }

    public function getProxyUrl(): ?string
    {
        return $this->getCurrentProvider()?->getProxyUrl();
    }

    public function getProxyConfig(): array
    {
        return $this->getCurrentProvider()?->getProxyConfig() ?? [];
    }

    public function isAvailable(): bool
    {
        return $this->getCurrentProvider() !== null;
    }

    public function rotate(): void
    {
        // Rotate the current provider's session
        $currentProvider = $this->getCurrentProvider();
        $currentProvider?->rotate();

        // Move to next provider in round-robin fashion
        if (!empty($this->providers)) {
            $this->currentIndex = ($this->currentIndex + 1) % count($this->providers);
        }
    }

    /**
     * Get all registered providers.
     *
     * @return array<ProxyAdapterInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }
}
