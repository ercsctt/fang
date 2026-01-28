<?php

declare(strict_types=1);

namespace App\Crawler\DTOs;

readonly class ProductImage
{
    public function __construct(
        public string $url,
        public ?string $altText = null,
        public bool $isPrimary = false,
        public ?int $width = null,
        public ?int $height = null,
    ) {}

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'alt_text' => $this->altText,
            'is_primary' => $this->isPrimary,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
