<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Sentiment;
use App\Models\ProductListing;
use App\Models\ProductListingReview;

class ReviewAnalyzer
{
    /**
     * Positive sentiment keywords with weights (0-1 scale).
     *
     * @var array<string, float>
     */
    private const POSITIVE_KEYWORDS = [
        // Strong positive (0.8+)
        'love' => 0.9,
        'loves' => 0.9,
        'loved' => 0.9,
        'amazing' => 0.9,
        'excellent' => 0.9,
        'fantastic' => 0.9,
        'perfect' => 0.9,
        'best' => 0.85,
        'wonderful' => 0.85,
        'brilliant' => 0.85,
        'outstanding' => 0.85,
        'superb' => 0.85,

        // Medium positive (0.5-0.8)
        'great' => 0.7,
        'good' => 0.6,
        'happy' => 0.7,
        'pleased' => 0.7,
        'recommend' => 0.75,
        'recommended' => 0.75,
        'recommends' => 0.75,
        'tasty' => 0.7,
        'delicious' => 0.8,
        'healthy' => 0.65,
        'quality' => 0.6,
        'value' => 0.55,
        'fresh' => 0.6,
        'favourite' => 0.8,
        'favorite' => 0.8,
        'enjoys' => 0.7,
        'enjoy' => 0.7,
        'enjoyed' => 0.7,

        // Mild positive (0.3-0.5)
        'nice' => 0.5,
        'fine' => 0.4,
        'decent' => 0.4,
        'okay' => 0.35,
        'ok' => 0.35,
        'satisfactory' => 0.45,
        'acceptable' => 0.4,

        // Pet-specific positive
        'devours' => 0.85,
        'gobbles' => 0.8,
        'devour' => 0.85,
        'gobble' => 0.8,
        'licks' => 0.7,
        'eager' => 0.65,
        'excited' => 0.7,
        'thrilled' => 0.8,
        'energetic' => 0.6,
        'shiny coat' => 0.7,
        'glossy' => 0.6,
        'healthier' => 0.7,
    ];

    /**
     * Negative sentiment keywords with weights (0-1 scale, applied as negative).
     *
     * @var array<string, float>
     */
    private const NEGATIVE_KEYWORDS = [
        // Strong negative (0.8+)
        'terrible' => 0.9,
        'awful' => 0.9,
        'horrible' => 0.9,
        'disgusting' => 0.9,
        'worst' => 0.9,
        'hate' => 0.85,
        'hated' => 0.85,
        'hates' => 0.85,
        'dangerous' => 0.9,
        'toxic' => 0.95,
        'poisoned' => 0.95,
        'sick' => 0.85,
        'vomit' => 0.85,
        'vomiting' => 0.85,
        'diarrhea' => 0.8,
        'diarrhoea' => 0.8,

        // Medium negative (0.5-0.8)
        'bad' => 0.7,
        'poor' => 0.65,
        'disappointed' => 0.75,
        'disappointing' => 0.75,
        'refuse' => 0.7,
        'refused' => 0.7,
        'refuses' => 0.7,
        'reject' => 0.65,
        'rejected' => 0.65,
        'waste' => 0.7,
        'wasted' => 0.7,
        'overpriced' => 0.6,
        'expensive' => 0.5,
        'stale' => 0.7,
        'rotten' => 0.85,
        'mouldy' => 0.85,
        'moldy' => 0.85,

        // Mild negative (0.3-0.5)
        'mediocre' => 0.5,
        'bland' => 0.45,
        'boring' => 0.4,
        'average' => 0.3,
        'meh' => 0.4,

        // Pet-specific negative
        'constipation' => 0.7,
        'itchy' => 0.6,
        'scratching' => 0.55,
        'allergic' => 0.7,
        'allergy' => 0.7,
        'upset stomach' => 0.75,
        'stomach upset' => 0.75,
        'gas' => 0.5,
        'gassy' => 0.55,
        'flatulence' => 0.55,
        'picky' => 0.4,
        'fussy' => 0.4,
    ];

    /**
     * Negation words that flip sentiment.
     *
     * @var list<string>
     */
    private const NEGATION_WORDS = [
        'not',
        "n't",
        'no',
        'never',
        'neither',
        'nobody',
        'nothing',
        'nowhere',
        'hardly',
        'barely',
        'without',
    ];

    /**
     * Intensifier words that boost sentiment strength.
     *
     * @var array<string, float>
     */
    private const INTENSIFIERS = [
        'very' => 1.3,
        'really' => 1.3,
        'extremely' => 1.5,
        'absolutely' => 1.5,
        'completely' => 1.4,
        'totally' => 1.4,
        'highly' => 1.3,
        'incredibly' => 1.4,
        'so' => 1.2,
        'quite' => 1.1,
        'rather' => 1.1,
    ];

    /**
     * Common praise topics to identify.
     *
     * @var array<string, list<string>>
     */
    private const PRAISE_TOPICS = [
        'taste' => ['tasty', 'delicious', 'loves', 'devours', 'gobbles', 'enjoys', 'flavour', 'flavor'],
        'quality' => ['quality', 'premium', 'excellent', 'high-quality', 'best'],
        'value' => ['value', 'worth', 'bargain', 'affordable', 'price'],
        'health' => ['healthy', 'healthier', 'shiny', 'coat', 'energy', 'weight', 'digestion'],
        'ingredients' => ['ingredients', 'natural', 'grain-free', 'protein', 'meat'],
    ];

    /**
     * Common complaint topics to identify.
     *
     * @var array<string, list<string>>
     */
    private const COMPLAINT_TOPICS = [
        'taste' => ['refuse', 'reject', 'picky', 'fussy', 'bland', "won't eat", 'doesn\'t like'],
        'quality' => ['stale', 'mouldy', 'moldy', 'rotten', 'broken', 'damaged', 'poor quality'],
        'value' => ['overpriced', 'expensive', 'not worth', 'waste of money', 'rip off'],
        'health' => ['sick', 'vomit', 'diarrhea', 'diarrhoea', 'allergy', 'allergic', 'itchy', 'upset stomach'],
        'packaging' => ['packaging', 'damaged', 'leaking', 'broken', 'torn', 'crushed'],
        'delivery' => ['delivery', 'arrived', 'late', 'missing', 'wrong'],
    ];

    /**
     * Analyze a single review and return sentiment analysis results.
     *
     * @return array{
     *     score: float,
     *     sentiment: Sentiment,
     *     confidence: float,
     *     positive_keywords: list<string>,
     *     negative_keywords: list<string>,
     *     topics: array{praises: list<string>, complaints: list<string>}
     * }
     */
    public function analyze(ProductListingReview $review): array
    {
        $text = $this->prepareText($review);
        $words = $this->tokenize($text);

        $positiveMatches = [];
        $negativeMatches = [];
        $positiveScore = 0.0;
        $negativeScore = 0.0;

        $negationWindow = 3; // Number of words affected by negation
        $negationActive = false;
        $wordsSinceNegation = 0;
        $intensifier = 1.0;

        foreach ($words as $i => $word) {
            // Check for negation
            if ($this->isNegation($word)) {
                $negationActive = true;
                $wordsSinceNegation = 0;

                continue;
            }

            // Check for intensifiers
            if (isset(self::INTENSIFIERS[$word])) {
                $intensifier = self::INTENSIFIERS[$word];

                continue;
            }

            // Track negation window
            if ($negationActive) {
                $wordsSinceNegation++;
                if ($wordsSinceNegation > $negationWindow) {
                    $negationActive = false;
                }
            }

            // Check for positive keywords
            if (isset(self::POSITIVE_KEYWORDS[$word])) {
                $weight = self::POSITIVE_KEYWORDS[$word] * $intensifier;

                if ($negationActive) {
                    $negativeScore += $weight * 0.8; // Negated positive becomes negative
                    $negativeMatches[] = "not {$word}";
                } else {
                    $positiveScore += $weight;
                    $positiveMatches[] = $word;
                }
            }

            // Check for negative keywords
            if (isset(self::NEGATIVE_KEYWORDS[$word])) {
                $weight = self::NEGATIVE_KEYWORDS[$word] * $intensifier;

                if ($negationActive) {
                    $positiveScore += $weight * 0.5; // Negated negative becomes mildly positive
                    $positiveMatches[] = "not {$word}";
                } else {
                    $negativeScore += $weight;
                    $negativeMatches[] = $word;
                }
            }

            // Reset intensifier after use
            $intensifier = 1.0;
        }

        // Check for multi-word phrases
        $this->checkPhrases($text, $positiveScore, $negativeScore, $positiveMatches, $negativeMatches);

        // Factor in the star rating
        $ratingFactor = $this->ratingToSentimentFactor($review->rating);

        // Calculate final score (-1.0 to 1.0)
        $totalMatches = count($positiveMatches) + count($negativeMatches);
        $keywordScore = $totalMatches > 0
            ? ($positiveScore - $negativeScore) / max($totalMatches, 1)
            : 0;

        // Weighted combination of keyword analysis and rating
        // Rating is a stronger signal, but keywords provide nuance
        $finalScore = ($keywordScore * 0.4) + ($ratingFactor * 0.6);
        $finalScore = max(-1.0, min(1.0, $finalScore));

        // Calculate confidence based on number of signals
        $confidence = $this->calculateConfidence($totalMatches, $review->rating !== null);

        // Identify topics
        $topics = $this->identifyTopics($text);

        return [
            'score' => round($finalScore, 3),
            'sentiment' => Sentiment::fromScore($finalScore),
            'confidence' => round($confidence, 2),
            'positive_keywords' => array_unique($positiveMatches),
            'negative_keywords' => array_unique($negativeMatches),
            'topics' => $topics,
        ];
    }

    /**
     * Analyze multiple reviews and return aggregate statistics.
     *
     * @param  iterable<ProductListingReview>  $reviews
     * @return array{
     *     average_score: float,
     *     sentiment_distribution: array{positive: int, neutral: int, negative: int},
     *     sentiment_percentages: array{positive: float, neutral: float, negative: float},
     *     average_confidence: float,
     *     common_praises: array<string, int>,
     *     common_complaints: array<string, int>,
     *     top_positive_keywords: array<string, int>,
     *     top_negative_keywords: array<string, int>,
     *     total_reviews: int
     * }
     */
    public function analyzeMultiple(iterable $reviews): array
    {
        $totalScore = 0.0;
        $totalConfidence = 0.0;
        $count = 0;

        $sentimentCounts = [
            'positive' => 0,
            'neutral' => 0,
            'negative' => 0,
        ];

        $praiseCounts = [];
        $complaintCounts = [];
        $positiveKeywordCounts = [];
        $negativeKeywordCounts = [];

        foreach ($reviews as $review) {
            $analysis = $this->analyze($review);

            $totalScore += $analysis['score'];
            $totalConfidence += $analysis['confidence'];
            $sentimentCounts[$analysis['sentiment']->value]++;

            foreach ($analysis['topics']['praises'] as $praise) {
                $praiseCounts[$praise] = ($praiseCounts[$praise] ?? 0) + 1;
            }

            foreach ($analysis['topics']['complaints'] as $complaint) {
                $complaintCounts[$complaint] = ($complaintCounts[$complaint] ?? 0) + 1;
            }

            foreach ($analysis['positive_keywords'] as $keyword) {
                $positiveKeywordCounts[$keyword] = ($positiveKeywordCounts[$keyword] ?? 0) + 1;
            }

            foreach ($analysis['negative_keywords'] as $keyword) {
                $negativeKeywordCounts[$keyword] = ($negativeKeywordCounts[$keyword] ?? 0) + 1;
            }

            $count++;
        }

        if ($count === 0) {
            return [
                'average_score' => 0.0,
                'sentiment_distribution' => $sentimentCounts,
                'sentiment_percentages' => ['positive' => 0.0, 'neutral' => 0.0, 'negative' => 0.0],
                'average_confidence' => 0.0,
                'common_praises' => [],
                'common_complaints' => [],
                'top_positive_keywords' => [],
                'top_negative_keywords' => [],
                'total_reviews' => 0,
            ];
        }

        arsort($praiseCounts);
        arsort($complaintCounts);
        arsort($positiveKeywordCounts);
        arsort($negativeKeywordCounts);

        return [
            'average_score' => round($totalScore / $count, 3),
            'sentiment_distribution' => $sentimentCounts,
            'sentiment_percentages' => [
                'positive' => round(($sentimentCounts['positive'] / $count) * 100, 1),
                'neutral' => round(($sentimentCounts['neutral'] / $count) * 100, 1),
                'negative' => round(($sentimentCounts['negative'] / $count) * 100, 1),
            ],
            'average_confidence' => round($totalConfidence / $count, 2),
            'common_praises' => array_slice($praiseCounts, 0, 5, true),
            'common_complaints' => array_slice($complaintCounts, 0, 5, true),
            'top_positive_keywords' => array_slice($positiveKeywordCounts, 0, 10, true),
            'top_negative_keywords' => array_slice($negativeKeywordCounts, 0, 10, true),
            'total_reviews' => $count,
        ];
    }

    /**
     * Analyze all reviews for a product listing.
     *
     * @return array{
     *     average_score: float,
     *     sentiment_distribution: array{positive: int, neutral: int, negative: int},
     *     sentiment_percentages: array{positive: float, neutral: float, negative: float},
     *     average_confidence: float,
     *     common_praises: array<string, int>,
     *     common_complaints: array<string, int>,
     *     top_positive_keywords: array<string, int>,
     *     top_negative_keywords: array<string, int>,
     *     total_reviews: int
     * }
     */
    public function analyzeProductListing(ProductListing $productListing): array
    {
        return $this->analyzeMultiple($productListing->reviews()->cursor());
    }

    /**
     * Generate a human-readable summary of reviews.
     */
    public function generateSummary(ProductListing $productListing): string
    {
        $analysis = $this->analyzeProductListing($productListing);

        if ($analysis['total_reviews'] === 0) {
            return 'No reviews available for this product.';
        }

        $parts = [];

        // Overall sentiment
        $overallSentiment = Sentiment::fromScore($analysis['average_score']);
        $parts[] = $this->generateOverallSentimentText($overallSentiment, $analysis);

        // Common praises
        if (! empty($analysis['common_praises'])) {
            $praiseTopics = array_keys($analysis['common_praises']);
            $parts[] = $this->generatePraiseText($praiseTopics);
        }

        // Common complaints
        if (! empty($analysis['common_complaints'])) {
            $complaintTopics = array_keys($analysis['common_complaints']);
            $parts[] = $this->generateComplaintText($complaintTopics);
        }

        return implode(' ', $parts);
    }

    /**
     * Prepare text for analysis by combining title and body.
     */
    private function prepareText(ProductListingReview $review): string
    {
        $text = '';

        if ($review->title !== null && $review->title !== '') {
            $text .= $review->title.' ';
        }

        if ($review->body !== null && $review->body !== '') {
            $text .= $review->body;
        }

        return mb_strtolower(trim($text));
    }

    /**
     * Tokenize text into words.
     *
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        // Remove punctuation except apostrophes
        $text = preg_replace("/[^\w\s']/u", ' ', $text) ?? $text;

        // Split into words
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?? [];

        return array_values($words);
    }

    /**
     * Check if a word is a negation.
     */
    private function isNegation(string $word): bool
    {
        return in_array($word, self::NEGATION_WORDS, true)
            || str_ends_with($word, "n't");
    }

    /**
     * Check for multi-word phrases in the text.
     *
     * @param  list<string>  $positiveMatches
     * @param  list<string>  $negativeMatches
     */
    private function checkPhrases(
        string $text,
        float &$positiveScore,
        float &$negativeScore,
        array &$positiveMatches,
        array &$negativeMatches
    ): void {
        // Positive phrases
        $positivePhrases = [
            'highly recommend' => 0.85,
            'would recommend' => 0.8,
            'definitely recommend' => 0.85,
            'five stars' => 0.9,
            '5 stars' => 0.9,
            'great value' => 0.75,
            'value for money' => 0.7,
            'good value' => 0.65,
            'shiny coat' => 0.7,
            'healthy coat' => 0.7,
            'full of energy' => 0.7,
            'cleans teeth' => 0.6,
        ];

        // Negative phrases
        $negativePhrases = [
            'would not recommend' => 0.85,
            'do not recommend' => 0.85,
            'waste of money' => 0.8,
            'not worth' => 0.7,
            'rip off' => 0.75,
            "won't eat" => 0.75,
            'will not eat' => 0.75,
            "doesn't eat" => 0.7,
            'does not eat' => 0.7,
            'upset stomach' => 0.75,
            'stomach upset' => 0.75,
            'made sick' => 0.85,
            'got sick' => 0.85,
            'poor quality' => 0.7,
        ];

        foreach ($positivePhrases as $phrase => $weight) {
            if (str_contains($text, $phrase)) {
                $positiveScore += $weight;
                $positiveMatches[] = $phrase;
            }
        }

        foreach ($negativePhrases as $phrase => $weight) {
            if (str_contains($text, $phrase)) {
                $negativeScore += $weight;
                $negativeMatches[] = $phrase;
            }
        }
    }

    /**
     * Convert a star rating to a sentiment factor.
     */
    private function ratingToSentimentFactor(?float $rating): float
    {
        if ($rating === null) {
            return 0.0;
        }

        // Map 1-5 stars to -1.0 to 1.0
        // 1 star = -1.0, 3 stars = 0.0, 5 stars = 1.0
        return ($rating - 3) / 2;
    }

    /**
     * Calculate confidence score based on available signals.
     */
    private function calculateConfidence(int $keywordMatches, bool $hasRating): float
    {
        $confidence = 0.3; // Base confidence

        if ($hasRating) {
            $confidence += 0.4;
        }

        // More keywords = more confidence (up to a point)
        $keywordBonus = min($keywordMatches * 0.1, 0.3);
        $confidence += $keywordBonus;

        return min($confidence, 1.0);
    }

    /**
     * Identify praise and complaint topics in the text.
     *
     * @return array{praises: list<string>, complaints: list<string>}
     */
    private function identifyTopics(string $text): array
    {
        $praises = [];
        $complaints = [];

        foreach (self::PRAISE_TOPICS as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $praises[] = $topic;
                    break;
                }
            }
        }

        foreach (self::COMPLAINT_TOPICS as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $complaints[] = $topic;
                    break;
                }
            }
        }

        return [
            'praises' => array_unique($praises),
            'complaints' => array_unique($complaints),
        ];
    }

    /**
     * Generate overall sentiment text for summary.
     *
     * @param  array{sentiment_percentages: array{positive: float, neutral: float, negative: float}, total_reviews: int}  $analysis
     */
    private function generateOverallSentimentText(Sentiment $sentiment, array $analysis): string
    {
        $reviewCount = $analysis['total_reviews'];
        $reviewWord = $reviewCount === 1 ? 'review' : 'reviews';

        return match ($sentiment) {
            Sentiment::Positive => "Based on {$reviewCount} {$reviewWord}, customers are generally pleased with this product ({$analysis['sentiment_percentages']['positive']}% positive).",
            Sentiment::Neutral => "Based on {$reviewCount} {$reviewWord}, customer opinions on this product are mixed.",
            Sentiment::Negative => "Based on {$reviewCount} {$reviewWord}, customers have expressed concerns about this product ({$analysis['sentiment_percentages']['negative']}% negative).",
        };
    }

    /**
     * Generate praise text for summary.
     *
     * @param  list<string>  $topics
     */
    private function generatePraiseText(array $topics): string
    {
        $topicLabels = [
            'taste' => 'taste and palatability',
            'quality' => 'product quality',
            'value' => 'value for money',
            'health' => 'health benefits',
            'ingredients' => 'ingredient quality',
        ];

        $labels = array_map(fn ($t) => $topicLabels[$t] ?? $t, array_slice($topics, 0, 3));

        return 'Customers commonly praise the '.$this->formatList($labels).'.';
    }

    /**
     * Generate complaint text for summary.
     *
     * @param  list<string>  $topics
     */
    private function generateComplaintText(array $topics): string
    {
        $topicLabels = [
            'taste' => 'pets refusing to eat it',
            'quality' => 'product quality issues',
            'value' => 'price concerns',
            'health' => 'health-related issues',
            'packaging' => 'packaging problems',
            'delivery' => 'delivery issues',
        ];

        $labels = array_map(fn ($t) => $topicLabels[$t] ?? $t, array_slice($topics, 0, 3));

        return 'Some customers have mentioned '.$this->formatList($labels).'.';
    }

    /**
     * Format a list of items with commas and "and".
     *
     * @param  list<string>  $items
     */
    private function formatList(array $items): string
    {
        if (count($items) === 0) {
            return '';
        }

        if (count($items) === 1) {
            return $items[0];
        }

        if (count($items) === 2) {
            return $items[0].' and '.$items[1];
        }

        $last = array_pop($items);

        return implode(', ', $items).', and '.$last;
    }
}
