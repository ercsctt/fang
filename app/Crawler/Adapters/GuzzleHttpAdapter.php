<?php

declare(strict_types=1);

namespace App\Crawler\Adapters;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Contracts\ProxyAdapterInterface;
use App\Crawler\Contracts\SupportsProxyInterface;
use App\Crawler\Services\UserAgentRotator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Production-ready HTTP adapter with anti-bot protection.
 * Features: rotating user agents, realistic browser headers, proxy support, comprehensive logging.
 */
class GuzzleHttpAdapter implements HttpAdapterInterface, SupportsProxyInterface
{
    private ?int $lastStatusCode = null;
    /** @var array<string, string> */
    private array $lastHeaders = [];
    private ?ProxyAdapterInterface $proxyAdapter = null;
    private UserAgentRotator $userAgentRotator;

    public function __construct(
        private readonly Client $client = new Client(),
        private readonly bool $rotateUserAgent = true,
        private readonly bool $rotateProxy = true,
    ) {
        $this->userAgentRotator = new UserAgentRotator();
    }

    public function fetchHtml(string $url, array $options = []): string
    {
        // Rotate proxy if enabled and available
        if ($this->rotateProxy && $this->proxyAdapter?->isAvailable()) {
            $this->proxyAdapter->rotate();
        }

        $defaultOptions = [
            'headers' => $this->buildHeaders($options['headers'] ?? []),
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify' => true,
            'allow_redirects' => [
                'max' => 5,
                'strict' => true,
                'track_redirects' => true,
            ],
            'http_errors' => true,
        ];

        // Apply proxy configuration if available
        if ($this->proxyAdapter?->isAvailable()) {
            $proxyConfig = $this->proxyAdapter->getProxyConfig();
            $defaultOptions = array_merge($defaultOptions, $proxyConfig);

            Log::debug("Using proxy for request", [
                'url' => $url,
                'proxy' => $this->maskProxyCredentials($this->proxyAdapter->getProxyUrl()),
            ]);
        }

        $mergedOptions = array_merge_recursive($defaultOptions, $options);

        try {
            $response = $this->client->get($url, $mergedOptions);

            $this->lastStatusCode = $response->getStatusCode();
            $this->lastHeaders = $this->formatHeaders($response->getHeaders());

            Log::debug("Successfully fetched URL", [
                'url' => $url,
                'status' => $this->lastStatusCode,
                'size' => strlen((string) $response->getBody()),
            ]);

            return (string) $response->getBody();
        } catch (GuzzleException $e) {
            Log::warning("Failed to fetch URL", [
                'url' => $url,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            throw new \Exception("Failed to fetch URL: {$url}. Error: {$e->getMessage()}", 0, $e);
        }
    }

    public function getLastStatusCode(): ?int
    {
        return $this->lastStatusCode;
    }

    public function getLastHeaders(): array
    {
        return $this->lastHeaders;
    }

    /**
     * Build realistic browser headers with anti-bot protection.
     *
     * @param array<string, string> $customHeaders
     * @return array<string, string>
     */
    private function buildHeaders(array $customHeaders = []): array
    {
        $headers = [
            'User-Agent' => $this->rotateUserAgent
                ? $this->userAgentRotator->next()
                : $this->userAgentRotator->random(),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => 'en-GB,en;q=0.9,en-US;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
            'Cache-Control' => 'max-age=0',
        ];

        // Merge custom headers (they take precedence)
        return array_merge($headers, $customHeaders);
    }

    /**
     * Format response headers to a simple key-value array.
     *
     * @param array<string, array<string>> $headers
     * @return array<string, string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $values) {
            $formatted[$name] = implode(', ', $values);
        }
        return $formatted;
    }

    /**
     * Mask proxy credentials for logging.
     */
    private function maskProxyCredentials(?string $proxyUrl): ?string
    {
        if (!$proxyUrl) {
            return null;
        }

        return preg_replace('/\/\/([^:]+):([^@]+)@/', '//***:***@', $proxyUrl);
    }

    public function withProxy(ProxyAdapterInterface $proxyAdapter): static
    {
        $this->proxyAdapter = $proxyAdapter;
        return $this;
    }

    public function getProxyAdapter(): ?ProxyAdapterInterface
    {
        return $this->proxyAdapter;
    }

    /**
     * Get the user agent rotator instance.
     */
    public function getUserAgentRotator(): UserAgentRotator
    {
        return $this->userAgentRotator;
    }
}
