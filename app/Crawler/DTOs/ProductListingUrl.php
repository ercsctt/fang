<?php

declare(strict_types=1);

namespace App\Crawler\DTOs;

readonly class ProductListingUrl
{
    public function __construct(
        public string $url,
        public string $retailer,
        public ?string $category = null,
        public ?array $metadata = null,
    ) {}

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'retailer' => $this->retailer,
            'category' => $this->category,
            'metadata' => $this->metadata,
        ];
    }
}
