<?php

declare(strict_types=1);

namespace App\Crawler\DTOs;

use DateTimeInterface;

readonly class ProductReview
{
    public function __construct(
        public string $externalId,
        public float $rating,
        public ?string $author,
        public ?string $title,
        public string $body,
        public bool $verifiedPurchase = false,
        public ?DateTimeInterface $reviewDate = null,
        public int $helpfulCount = 0,
        public ?array $metadata = null,
    ) {}

    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'rating' => $this->rating,
            'author' => $this->author,
            'title' => $this->title,
            'body' => $this->body,
            'verified_purchase' => $this->verifiedPurchase,
            'review_date' => $this->reviewDate?->format('Y-m-d H:i:s'),
            'helpful_count' => $this->helpfulCount,
            'metadata' => $this->metadata,
        ];
    }
}
