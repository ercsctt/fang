<?php

declare(strict_types=1);

namespace App\Crawler\DTOs;

/**
 * Represents a paginated URL discovered during category crawling.
 * Used to yield next page URLs for the crawler to follow.
 */
readonly class PaginatedUrl
{
    public function __construct(
        public string $url,
        public string $retailer,
        public int $page,
        public ?string $category = null,
        public ?string $discoveredFrom = null,
    ) {}

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'retailer' => $this->retailer,
            'page' => $this->page,
            'category' => $this->category,
            'discovered_from' => $this->discoveredFrom,
        ];
    }
}
