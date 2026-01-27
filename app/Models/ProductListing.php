<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductListing extends Model
{
    /** @use HasFactory<\Database\Factories\ProductListingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'retailer_id',
        'external_id',
        'barcode',
        'url',
        'title',
        'description',
        'price_pence',
        'original_price_pence',
        'currency',
        'weight_grams',
        'quantity',
        'brand',
        'category',
        'images',
        'ingredients',
        'nutritional_info',
        'in_stock',
        'stock_quantity',
        'last_scraped_at',
        'last_reviews_scraped_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'images' => 'array',
            'nutritional_info' => 'array',
            'in_stock' => 'boolean',
            'last_scraped_at' => 'datetime',
            'last_reviews_scraped_at' => 'datetime',
            'price_pence' => 'integer',
            'original_price_pence' => 'integer',
            'weight_grams' => 'integer',
            'quantity' => 'integer',
            'stock_quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Retailer, $this>
     */
    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    /**
     * @return HasMany<ProductListingPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductListingPrice::class);
    }

    /**
     * @return HasMany<ProductListingReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductListingReview::class);
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_listing_matches')
            ->withPivot(['confidence_score', 'match_type', 'matched_at', 'verified_by', 'verified_at'])
            ->withTimestamps();
    }

    /**
     * @param  Builder<ProductListing>  $query
     * @return Builder<ProductListing>
     */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('in_stock', true);
    }

    /**
     * @param  Builder<ProductListing>  $query
     * @return Builder<ProductListing>
     */
    public function scopeOnSale(Builder $query): Builder
    {
        return $query->whereNotNull('original_price_pence')
            ->whereColumn('price_pence', '<', 'original_price_pence');
    }

    /**
     * @param  Builder<ProductListing>  $query
     * @return Builder<ProductListing>
     */
    public function scopeByRetailer(Builder $query, int $retailerId): Builder
    {
        return $query->where('retailer_id', $retailerId);
    }

    /**
     * @param  Builder<ProductListing>  $query
     * @return Builder<ProductListing>
     */
    public function scopeByBrand(Builder $query, string $brand): Builder
    {
        return $query->where('brand', $brand);
    }

    /**
     * @param  Builder<ProductListing>  $query
     * @return Builder<ProductListing>
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * @param  Builder<ProductListing>  $query
     * @return Builder<ProductListing>
     */
    public function scopeNeedsScraping(Builder $query, int $hours = 24): Builder
    {
        return $query->where(function (Builder $query) use ($hours) {
            $query->whereNull('last_scraped_at')
                ->orWhere('last_scraped_at', '<', now()->subHours($hours));
        });
    }

    /**
     * @param  Builder<ProductListing>  $query
     * @return Builder<ProductListing>
     */
    public function scopeNeedsReviewScraping(Builder $query, int $days = 7): Builder
    {
        return $query->where(function (Builder $query) use ($days) {
            $query->whereNull('last_reviews_scraped_at')
                ->orWhere('last_reviews_scraped_at', '<', now()->subDays($days));
        });
    }

    /**
     * @param  Builder<ProductListing>  $query
     * @return Builder<ProductListing>
     */
    public function scopeByBarcode(Builder $query, string $barcode): Builder
    {
        return $query->where('barcode', $barcode);
    }

    /**
     * @param  Builder<ProductListing>  $query
     * @return Builder<ProductListing>
     */
    public function scopeWithBarcode(Builder $query): Builder
    {
        return $query->whereNotNull('barcode');
    }

    public function getPriceFormattedAttribute(): ?string
    {
        if ($this->price_pence === null) {
            return null;
        }

        return '£'.number_format($this->price_pence / 100, 2);
    }

    public function isOnSale(): bool
    {
        return $this->original_price_pence !== null
            && $this->price_pence !== null
            && $this->price_pence < $this->original_price_pence;
    }

    public function recordPrice(): void
    {
        $latestPrice = $this->prices()->latest()->first();

        if ($latestPrice === null || $latestPrice->price_pence !== $this->price_pence) {
            $this->prices()->create([
                'price_pence' => $this->price_pence,
                'original_price_pence' => $this->original_price_pence,
                'currency' => $this->currency,
                'recorded_at' => now(),
            ]);
        }
    }
}
