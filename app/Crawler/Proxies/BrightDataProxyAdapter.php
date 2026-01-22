<?php

declare(strict_types=1);

namespace App\Crawler\Proxies;

use App\Crawler\Contracts\ProxyAdapterInterface;

/**
 * BrightData (formerly Luminati) proxy adapter.
 * Provides residential/datacenter proxies with automatic rotation.
 *
 * @see https://brightdata.com/
 */
class BrightDataProxyAdapter implements ProxyAdapterInterface
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $zone;
    private ?string $country;
    private int $sessionId;

    public function __construct(
        ?string $username = null,
        ?string $password = null,
        ?string $zone = null,
        ?string $host = null,
        ?int $port = null,
        ?string $country = null,
    ) {
        $this->username = $username ?? config('services.brightdata.username');
        $this->password = $password ?? config('services.brightdata.password');
        $this->zone = $zone ?? config('services.brightdata.zone', 'residential');
        $this->host = $host ?? config('services.brightdata.host', 'brd.superproxy.io');
        $this->port = $port ?? (int) config('services.brightdata.port', 22225);
        $this->country = $country ?? config('services.brightdata.country', 'gb');
        $this->sessionId = rand(1000000, 9999999);
    }

    public function getProxyUrl(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        // BrightData format: username-session-{session_id}-country-{country}
        $user = $this->buildUsername();

        return "http://{$user}:{$this->password}@{$this->host}:{$this->port}";
    }

    public function getProxyConfig(): array
    {
        $proxyUrl = $this->getProxyUrl();

        if (!$proxyUrl) {
            return [];
        }

        return [
            'proxy' => [
                'http' => $proxyUrl,
                'https' => $proxyUrl,
            ],
        ];
    }

    public function isAvailable(): bool
    {
        return !empty($this->username) && !empty($this->password);
    }

    public function rotate(): void
    {
        // Rotating the session ID will get a new IP from BrightData
        $this->sessionId = rand(1000000, 9999999);
    }

    /**
     * Build BrightData username with session and country parameters.
     */
    private function buildUsername(): string
    {
        $parts = [
            $this->username,
            "session-{$this->sessionId}",
        ];

        if ($this->country) {
            $parts[] = "country-{$this->country}";
        }

        return implode('-', $parts);
    }

    /**
     * Set the country code for the proxy.
     */
    public function setCountry(?string $country): self
    {
        $this->country = $country;
        return $this;
    }

    /**
     * Set the zone (residential, datacenter, mobile, etc).
     */
    public function setZone(string $zone): self
    {
        $this->zone = $zone;
        return $this;
    }

    /**
     * Create a sticky session (keeps same IP for multiple requests).
     */
    public function stickySession(int $sessionId): self
    {
        $this->sessionId = $sessionId;
        return $this;
    }
}
