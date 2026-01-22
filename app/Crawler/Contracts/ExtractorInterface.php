<?php

declare(strict_types=1);

namespace App\Crawler\Contracts;

use Generator;

interface ExtractorInterface
{
    /**
     * Extract data from HTML content.
     *
     * @param string $html The HTML content to extract from
     * @param string $url The URL the HTML was fetched from
     * @return Generator Yields DTOs containing extracted data
     */
    public function extract(string $html, string $url): Generator;

    /**
     * Check if this extractor can handle the given URL.
     */
    public function canHandle(string $url): bool;
}
