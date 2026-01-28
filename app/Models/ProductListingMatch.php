<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MatchType;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductListingMatch extends Model
{
    /** @use HasFactory<\Database\Factories\ProductListingMatchFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'product_listing_id',
        'confidence_score',
        'match_type',
        'matched_at',
        'verified_by',
        'verified_at',
        'status',
        'rejection_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence_score' => 'float',
            'match_type' => MatchType::class,
            'matched_at' => 'datetime',
            'verified_at' => 'datetime',
            'status' => VerificationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductListing, $this>
     */
    public function productListing(): BelongsTo
    {
        return $this->belongsTo(ProductListing::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @param  Builder<ProductListingMatch>  $query
     * @return Builder<ProductListingMatch>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at');
    }

    /**
     * @param  Builder<ProductListingMatch>  $query
     * @return Builder<ProductListingMatch>
     */
    public function scopeUnverified(Builder $query): Builder
    {
        return $query->whereNull('verified_at');
    }

    /**
     * @param  Builder<ProductListingMatch>  $query
     * @return Builder<ProductListingMatch>
     */
    public function scopeHighConfidence(Builder $query, float $min = 90.0): Builder
    {
        return $query->where('confidence_score', '>=', $min);
    }

    /**
     * @param  Builder<ProductListingMatch>  $query
     * @return Builder<ProductListingMatch>
     */
    public function scopeByType(Builder $query, MatchType $type): Builder
    {
        return $query->where('match_type', $type);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function verify(User $user): void
    {
        $this->verified_by = $user->id;
        $this->verified_at = now();
        $this->save();
    }

    public function approve(User $user): void
    {
        $this->status = VerificationStatus::Approved;
        $this->verified_by = $user->id;
        $this->verified_at = now();
        $this->rejection_reason = null;
        $this->save();
    }

    public function reject(User $user, ?string $reason = null): void
    {
        $this->status = VerificationStatus::Rejected;
        $this->verified_by = $user->id;
        $this->verified_at = now();
        $this->rejection_reason = $reason;
        $this->save();
    }

    public function isPending(): bool
    {
        return $this->status === VerificationStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === VerificationStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === VerificationStatus::Rejected;
    }

    /**
     * @param  Builder<ProductListingMatch>  $query
     * @return Builder<ProductListingMatch>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', VerificationStatus::Pending);
    }

    /**
     * @param  Builder<ProductListingMatch>  $query
     * @return Builder<ProductListingMatch>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', VerificationStatus::Approved);
    }

    /**
     * @param  Builder<ProductListingMatch>  $query
     * @return Builder<ProductListingMatch>
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', VerificationStatus::Rejected);
    }

    /**
     * @param  Builder<ProductListingMatch>  $query
     * @return Builder<ProductListingMatch>
     */
    public function scopeLowConfidence(Builder $query, float $max = 70.0): Builder
    {
        return $query->where('confidence_score', '<', $max);
    }

    /**
     * @param  Builder<ProductListingMatch>  $query
     * @return Builder<ProductListingMatch>
     */
    public function scopeOrderByConfidenceAsc(Builder $query): Builder
    {
        return $query->orderBy('confidence_score', 'asc');
    }
}
