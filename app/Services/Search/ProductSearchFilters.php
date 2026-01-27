<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\CanonicalCategory;

/**
 * @phpstan-type FilterArray array{
 *     query?: string|null,
 *     brand?: string|null,
 *     category?: string|null,
 *     canonical_category?: string|null,
 *     min_price?: int|null,
 *     max_price?: int|null,
 *     in_stock?: bool|null,
 *     verified?: bool|null,
 *     per_page?: int,
 *     page?: int
 * }
 */
readonly class ProductSearchFilters
{
    public function __construct(
        public ?string $query = null,
        public ?string $brand = null,
        public ?string $category = null,
        public ?CanonicalCategory $canonicalCategory = null,
        public ?int $minPricePence = null,
        public ?int $maxPricePence = null,
        public ?bool $inStock = null,
        public ?bool $verified = null,
        public int $perPage = 15,
        public int $page = 1,
    ) {}

    /**
     * @param  FilterArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            query: $data['query'] ?? null,
            brand: $data['brand'] ?? null,
            category: $data['category'] ?? null,
            canonicalCategory: isset($data['canonical_category'])
                ? CanonicalCategory::tryFrom($data['canonical_category'])
                : null,
            minPricePence: isset($data['min_price']) ? (int) $data['min_price'] : null,
            maxPricePence: isset($data['max_price']) ? (int) $data['max_price'] : null,
            inStock: isset($data['in_stock']) ? filter_var($data['in_stock'], FILTER_VALIDATE_BOOLEAN) : null,
            verified: isset($data['verified']) ? filter_var($data['verified'], FILTER_VALIDATE_BOOLEAN) : null,
            perPage: min((int) ($data['per_page'] ?? 15), 100),
            page: max((int) ($data['page'] ?? 1), 1),
        );
    }

    public function getCacheKey(): string
    {
        return 'product_search:'.md5(serialize([
            $this->query,
            $this->brand,
            $this->category,
            $this->canonicalCategory?->value,
            $this->minPricePence,
            $this->maxPricePence,
            $this->inStock,
            $this->verified,
            $this->perPage,
            $this->page,
        ]));
    }

    public function hasQuery(): bool
    {
        return $this->query !== null && trim($this->query) !== '';
    }
}
