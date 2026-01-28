<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductListingPrice extends Model
{
    /** @use HasFactory<\Database\Factories\ProductListingPriceFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'product_listing_id',
        'price_pence',
        'original_price_pence',
        'currency',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_pence' => 'integer',
            'original_price_pence' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductListing, $this>
     */
    public function productListing(): BelongsTo
    {
        return $this->belongsTo(ProductListing::class);
    }

    public function getPriceFormattedAttribute(): string
    {
        return '£'.number_format($this->price_pence / 100, 2);
    }

    public function isOnSale(): bool
    {
        return $this->original_price_pence !== null && $this->original_price_pence > $this->price_pence;
    }
}
