<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlStatistic extends Model
{
    /** @use HasFactory<\Database\Factories\CrawlStatisticFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'retailer_id',
        'date',
        'crawls_started',
        'crawls_completed',
        'crawls_failed',
        'listings_discovered',
        'details_extracted',
        'average_duration_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'crawls_started' => 'integer',
            'crawls_completed' => 'integer',
            'crawls_failed' => 'integer',
            'listings_discovered' => 'integer',
            'details_extracted' => 'integer',
            'average_duration_ms' => 'integer',
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
     * Calculate the success rate as a percentage.
     */
    public function getSuccessRateAttribute(): ?float
    {
        $total = $this->crawls_completed + $this->crawls_failed;

        if ($total === 0) {
            return null;
        }

        return round(($this->crawls_completed / $total) * 100, 2);
    }
}
