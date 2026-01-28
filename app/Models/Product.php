<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    use Searchable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'brand',
        'description',
        'category',
        'canonical_category',
        'subcategory',
        'weight_grams',
        'quantity',
        'primary_image',
        'average_price_pence',
        'lowest_price_pence',
        'is_verified',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'canonical_category' => \App\Enums\CanonicalCategory::class,
            'is_verified' => 'boolean',
            'metadata' => 'array',
            'weight_grams' => 'integer',
            'quantity' => 'integer',
            'average_price_pence' => 'integer',
            'lowest_price_pence' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Product $product): void {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });
    }

    /**
     * @return BelongsToMany<ProductListing, $this>
     */
    public function productListings(): BelongsToMany
    {
        return $this->belongsToMany(ProductListing::class, 'product_listing_matches')
            ->withPivot(['confidence_score', 'match_type', 'matched_at', 'verified_by', 'verified_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<PriceAlert, $this>
     */
    public function priceAlerts(): HasMany
    {
        return $this->hasMany(PriceAlert::class);
    }

    public function getLowestPriceFormattedAttribute(): ?string
    {
        if ($this->lowest_price_pence === null) {
            return null;
        }

        return '£'.number_format($this->lowest_price_pence / 100, 2);
    }

    public function getAveragePriceFormattedAttribute(): ?string
    {
        if ($this->average_price_pence === null) {
            return null;
        }

        return '£'.number_format($this->average_price_pence / 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand' => $this->brand,
            'description' => $this->description,
            'category' => $this->category,
            'canonical_category' => $this->canonical_category?->value,
            'subcategory' => $this->subcategory,
        ];
    }
}
