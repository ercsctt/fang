<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ProductSearchService
{
    private const int CACHE_TTL_SECONDS = 300; // 5 minutes

    private const int SUGGESTION_LIMIT = 10;

    /**
     * Search products with full-text search and filtering.
     *
     * @return LengthAwarePaginator<Product>
     */
    public function search(ProductSearchFilters $filters): LengthAwarePaginator
    {
        $cacheKey = $filters->getCacheKey();

        /** @var LengthAwarePaginator<Product> */
        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($filters) {
            return $this->buildSearchQuery($filters)
                ->paginate(perPage: $filters->perPage, page: $filters->page);
        });
    }

    /**
     * Get search suggestions for autocomplete.
     *
     * @return Collection<int, array{id: int, name: string, slug: string, brand: ?string, relevance: float}>
     */
    public function suggestions(string $query, int $limit = self::SUGGESTION_LIMIT): Collection
    {
        if (strlen($query) < 2) {
            return collect();
        }

        $cacheKey = 'product_suggestions:'.md5($query.':'.$limit);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($query, $limit) {
            return $this->buildSuggestionsQuery($query, $limit);
        });
    }

    /**
     * Get available filter options (brands, categories) for search refinement.
     *
     * @return array{brands: Collection<int, string>, categories: Collection<int, string>}
     */
    public function getFilterOptions(): array
    {
        return Cache::remember('product_filter_options', 3600, function () {
            return [
                'brands' => Product::query()
                    ->whereNotNull('brand')
                    ->distinct()
                    ->orderBy('brand')
                    ->pluck('brand'),
                'categories' => Product::query()
                    ->whereNotNull('category')
                    ->distinct()
                    ->orderBy('category')
                    ->pluck('category'),
            ];
        });
    }

    /**
     * @return Builder<Product>
     */
    private function buildSearchQuery(ProductSearchFilters $filters): Builder
    {
        $query = Product::query()
            ->withCount('productListings');

        // Apply full-text search if query provided
        if ($filters->hasQuery()) {
            $query = $this->applyFullTextSearch($query, $filters->query);
        }

        // Apply filters
        $this->applyFilters($query, $filters);

        // Apply sorting (relevance if searching, otherwise default)
        if (! $filters->hasQuery()) {
            $query->orderBy('name');
        }

        return $query;
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function applyFullTextSearch(Builder $query, ?string $searchQuery): Builder
    {
        if ($searchQuery === null || trim($searchQuery) === '') {
            return $query;
        }

        // Use PostgreSQL full-text search if available
        if ($this->supportsFullTextSearch()) {
            return $this->applyPostgresFullTextSearch($query, $searchQuery);
        }

        // Fallback to LIKE-based search for SQLite/other databases
        return $this->applyLikeSearch($query, $searchQuery);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function applyPostgresFullTextSearch(Builder $query, string $searchQuery): Builder
    {
        // Convert search query to tsquery format
        // Supports prefix matching with :* and phrase searching
        $tsquery = $this->buildTsQuery($searchQuery);

        return $query
            ->whereRaw('search_vector @@ to_tsquery(\'english\', ?)', [$tsquery])
            ->selectRaw('*, ts_rank_cd(search_vector, to_tsquery(\'english\', ?)) AS relevance', [$tsquery])
            ->orderByDesc('relevance');
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function applyLikeSearch(Builder $query, string $searchQuery): Builder
    {
        $searchTerms = '%'.str_replace(' ', '%', $searchQuery).'%';

        return $query
            ->where(function (Builder $q) use ($searchTerms) {
                $q->where('name', 'like', $searchTerms)
                    ->orWhere('brand', 'like', $searchTerms)
                    ->orWhere('description', 'like', $searchTerms)
                    ->orWhere('category', 'like', $searchTerms)
                    ->orWhere('subcategory', 'like', $searchTerms);
            })
            ->orderByRaw('
                CASE
                    WHEN name LIKE ? THEN 1
                    WHEN brand LIKE ? THEN 2
                    ELSE 3
                END
            ', [$searchTerms, $searchTerms]);
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applyFilters(Builder $query, ProductSearchFilters $filters): void
    {
        if ($filters->brand !== null) {
            $query->where('brand', $filters->brand);
        }

        if ($filters->category !== null) {
            $query->where('category', $filters->category);
        }

        if ($filters->canonicalCategory !== null) {
            $query->where('canonical_category', $filters->canonicalCategory);
        }

        if ($filters->minPricePence !== null) {
            $query->where('lowest_price_pence', '>=', $filters->minPricePence);
        }

        if ($filters->maxPricePence !== null) {
            $query->where('lowest_price_pence', '<=', $filters->maxPricePence);
        }

        if ($filters->inStock !== null) {
            $query->whereHas('productListings', function (Builder $q) use ($filters) {
                $q->where('in_stock', $filters->inStock);
            });
        }

        if ($filters->verified !== null) {
            $query->where('is_verified', $filters->verified);
        }
    }

    /**
     * @return Collection<int, array{id: int, name: string, slug: string, brand: ?string, relevance: float}>
     */
    private function buildSuggestionsQuery(string $query, int $limit): Collection
    {
        if ($this->supportsFullTextSearch()) {
            return $this->buildPostgresSuggestions($query, $limit);
        }

        return $this->buildLikeSuggestions($query, $limit);
    }

    /**
     * @return Collection<int, array{id: int, name: string, slug: string, brand: ?string, relevance: float}>
     */
    private function buildPostgresSuggestions(string $query, int $limit): Collection
    {
        $tsquery = $this->buildTsQuery($query);

        return Product::query()
            ->select(['id', 'name', 'slug', 'brand'])
            ->selectRaw('ts_rank_cd(search_vector, to_tsquery(\'english\', ?)) AS relevance', [$tsquery])
            ->whereRaw('search_vector @@ to_tsquery(\'english\', ?)', [$tsquery])
            ->orderByDesc('relevance')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'brand' => $product->brand,
                'relevance' => (float) $product->relevance,
            ]);
    }

    /**
     * @return Collection<int, array{id: int, name: string, slug: string, brand: ?string, relevance: float}>
     */
    private function buildLikeSuggestions(string $query, int $limit): Collection
    {
        $searchTerms = '%'.str_replace(' ', '%', $query).'%';

        return Product::query()
            ->select(['id', 'name', 'slug', 'brand'])
            ->where(function (Builder $q) use ($searchTerms) {
                $q->where('name', 'like', $searchTerms)
                    ->orWhere('brand', 'like', $searchTerms);
            })
            ->orderByRaw('
                CASE
                    WHEN name LIKE ? THEN 1
                    WHEN brand LIKE ? THEN 2
                    ELSE 3
                END
            ', [$searchTerms, $searchTerms])
            ->limit($limit)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'brand' => $product->brand,
                'relevance' => 1.0,
            ]);
    }

    /**
     * Build a PostgreSQL tsquery string from a search query.
     * Supports prefix matching and handles multiple terms.
     */
    private function buildTsQuery(string $searchQuery): string
    {
        // Sanitize and split query into words
        $words = preg_split('/\s+/', trim($searchQuery), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || count($words) === 0) {
            return '';
        }

        // Build tsquery with prefix matching for last word (autocomplete-friendly)
        $terms = array_map(function (string $word, int $index) use ($words) {
            // Escape special characters
            $escaped = preg_replace('/[^a-zA-Z0-9]/', '', $word);

            if ($escaped === null || $escaped === '') {
                return null;
            }

            // Add prefix match to last word for autocomplete
            if ($index === count($words) - 1) {
                return $escaped.':*';
            }

            return $escaped;
        }, $words, array_keys($words));

        // Filter out nulls and join with AND
        $terms = array_filter($terms);

        return implode(' & ', $terms);
    }

    private function supportsFullTextSearch(): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'pgsql') {
            return false;
        }

        // Check if the search_vector column exists
        return Schema::hasColumn('products', 'search_vector');
    }

    /**
     * Clear search caches. Useful when products are updated.
     */
    public function clearCache(): void
    {
        // In production, use cache tags or a more sophisticated approach
        Cache::forget('product_filter_options');
    }
}
