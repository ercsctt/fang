<?php

declare(strict_types=1);

namespace App\Crawler\Contracts;

interface HttpAdapterInterface
{
    /**
     * Fetch HTML content from a URL.
     *
     * @param string $url The URL to fetch
     * @param array<string, mixed> $options Additional options for the request
     * @return string The HTML content
     * @throws \Exception When the request fails
     */
    public function fetchHtml(string $url, array $options = []): string;

    /**
     * Get the last response status code.
     */
    public function getLastStatusCode(): ?int;

    /**
     * Get the last response headers.
     *
     * @return array<string, string>
     */
    public function getLastHeaders(): array;
}
