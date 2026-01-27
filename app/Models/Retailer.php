<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RetailerHealthStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Retailer extends Model
{
    /** @use HasFactory<\Database\Factories\RetailerFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'base_url',
        'crawler_class',
        'is_active',
        'health_status',
        'last_failure_at',
        'consecutive_failures',
        'paused_until',
        'rate_limit_ms',
        'last_crawled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'health_status' => RetailerHealthStatus::class,
            'last_failure_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'paused_until' => 'datetime',
            'last_crawled_at' => 'datetime',
        ];
    }

    /**
     * Check if the retailer is currently paused.
     */
    public function isPaused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }

    /**
     * Check if the retailer is available for crawling.
     */
    public function isAvailableForCrawling(): bool
    {
        return $this->is_active && ! $this->isPaused();
    }

    /**
     * @return HasMany<ProductListing, $this>
     */
    public function productListings(): HasMany
    {
        return $this->hasMany(ProductListing::class);
    }

    /**
     * @param  Builder<Retailer>  $query
     * @return Builder<Retailer>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
