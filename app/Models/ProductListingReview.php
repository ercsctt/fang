<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductListingReview extends Model
{
    /** @use HasFactory<\Database\Factories\ProductListingReviewFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_listing_id',
        'external_id',
        'author',
        'rating',
        'title',
        'body',
        'verified_purchase',
        'review_date',
        'helpful_count',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'verified_purchase' => 'boolean',
            'review_date' => 'date',
            'helpful_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ProductListing, $this>
     */
    public function productListing(): BelongsTo
    {
        return $this->belongsTo(ProductListing::class);
    }

    /**
     * @param  Builder<ProductListingReview>  $query
     * @return Builder<ProductListingReview>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verified_purchase', true);
    }

    /**
     * @param  Builder<ProductListingReview>  $query
     * @return Builder<ProductListingReview>
     */
    public function scopeHighRated(Builder $query, float $minRating = 4.0): Builder
    {
        return $query->where('rating', '>=', $minRating);
    }

    /**
     * @param  Builder<ProductListingReview>  $query
     * @return Builder<ProductListingReview>
     */
    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('review_date', '>=', now()->subDays($days));
    }

    /**
     * Get the integer star count (1-5) from the rating.
     */
    public function getStarsAttribute(): int
    {
        return (int) round($this->rating);
    }
}
