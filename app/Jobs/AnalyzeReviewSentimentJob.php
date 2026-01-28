<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ProductListingReview;
use App\Services\ReviewAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeReviewSentimentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        private readonly int $reviewId,
    ) {}

    public function handle(ReviewAnalyzer $analyzer): void
    {
        $review = ProductListingReview::find($this->reviewId);

        if (! $review) {
            Log::warning('Review not found for sentiment analysis', [
                'review_id' => $this->reviewId,
            ]);

            return;
        }

        // Skip if already analyzed
        if ($review->hasBeenAnalyzed()) {
            Log::info('Review already analyzed, skipping', [
                'review_id' => $this->reviewId,
            ]);

            return;
        }

        try {
            $analysis = $analyzer->analyze($review);

            $review->update([
                'sentiment' => $analysis['sentiment'],
                'sentiment_score' => $analysis['score'],
                'sentiment_confidence' => $analysis['confidence'],
                'sentiment_keywords' => [
                    'positive' => $analysis['positive_keywords'],
                    'negative' => $analysis['negative_keywords'],
                    'topics' => $analysis['topics'],
                ],
                'sentiment_analyzed_at' => now(),
            ]);

            Log::info('Review sentiment analyzed', [
                'review_id' => $this->reviewId,
                'product_listing_id' => $review->product_listing_id,
                'sentiment' => $analysis['sentiment']->value,
                'score' => $analysis['score'],
                'confidence' => $analysis['confidence'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to analyze review sentiment', [
                'review_id' => $this->reviewId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the tags for monitoring this job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'sentiment-analysis',
            'review:'.$this->reviewId,
        ];
    }
}
