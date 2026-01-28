<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class UserVerificationStat extends Model
{
    /** @use HasFactory<\Database\Factories\UserVerificationStatFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'date',
        'matches_approved',
        'matches_rejected',
        'matches_rematched',
        'bulk_approvals',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'matches_approved' => 'integer',
            'matches_rejected' => 'integer',
            'matches_rematched' => 'integer',
            'bulk_approvals' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get or create today's stats record for a user.
     */
    public static function forUserToday(User $user): self
    {
        return self::firstOrCreate(
            [
                'user_id' => $user->id,
                'date' => today(),
            ],
            [
                'matches_approved' => 0,
                'matches_rejected' => 0,
                'matches_rematched' => 0,
                'bulk_approvals' => 0,
            ]
        );
    }

    public function incrementApproved(int $count = 1): void
    {
        $this->increment('matches_approved', $count);
    }

    public function incrementRejected(int $count = 1): void
    {
        $this->increment('matches_rejected', $count);
    }

    public function incrementRematched(int $count = 1): void
    {
        $this->increment('matches_rematched', $count);
    }

    public function incrementBulkApprovals(int $count = 1): void
    {
        $this->increment('bulk_approvals', $count);
    }

    public function getTotalVerificationsAttribute(): int
    {
        return $this->matches_approved + $this->matches_rejected + $this->matches_rematched;
    }

    /**
     * @param  Builder<UserVerificationStat>  $query
     * @return Builder<UserVerificationStat>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * @param  Builder<UserVerificationStat>  $query
     * @return Builder<UserVerificationStat>
     */
    public function scopeForDate(Builder $query, Carbon $date): Builder
    {
        return $query->where('date', $date->toDateString());
    }

    /**
     * @param  Builder<UserVerificationStat>  $query
     * @return Builder<UserVerificationStat>
     */
    public function scopeBetweenDates(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end);
    }
}
