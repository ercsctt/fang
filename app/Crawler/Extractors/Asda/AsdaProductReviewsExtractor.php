<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Asda;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductReview;
use Carbon\Carbon;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AsdaProductReviewsExtractor implements ExtractorInterface
{
    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        $reviewCount = 0;

        // Try to extract from JSON-LD first (most reliable)
        $jsonLdReviews = $this->extractJsonLdReviews($crawler);
        foreach ($jsonLdReviews as $review) {
            $reviewCount++;
            yield $review;
        }

        // If no JSON-LD reviews, try DOM selectors
        if ($reviewCount === 0) {
            $reviewSelectors = [
                '[data-auto-id="review"]',
                '.review-item',
                '.customer-review',
                '.product-review',
                '[data-testid="review"]',
            ];

            foreach ($reviewSelectors as $selector) {
                try {
                    $reviews = $crawler->filter($selector);
                    if ($reviews->count() > 0) {
                        foreach ($reviews as $reviewNode) {
                            $review = $this->parseReview(new Crawler($reviewNode), $url, $reviewCount);
                            if ($review !== null) {
                                $reviewCount++;
                                yield $review;
                            }
                        }

                        if ($reviewCount > 0) {
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug("AsdaProductReviewsExtractor: Review selector {$selector} failed: {$e->getMessage()}");
                }
            }
        }

        Log::info("AsdaProductReviewsExtractor: Extracted {$reviewCount} reviews from {$url}");
    }

    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'groceries.asda.com')) {
            // Handle product pages (reviews embedded)
            if (preg_match('/\/product\/(?:[a-z0-9-]+\/)?(\d+)(?:\/|$|\?)/i', $url)) {
                return true;
            }
            // Handle dedicated review pages if Asda has them
            if (str_contains($url, '/reviews/') || str_contains($url, '/customer-reviews/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract reviews from JSON-LD structured data.
     *
     * @return Generator<ProductReview>
     */
    private function extractJsonLdReviews(Crawler $crawler): Generator
    {
        try {
            $scripts = $crawler->filter('script[type="application/ld+json"]');

            foreach ($scripts as $script) {
                $content = $script->textContent;
                $data = json_decode($content, true);

                if ($data === null) {
                    continue;
                }

                // Handle @graph format
                if (isset($data['@graph'])) {
                    foreach ($data['@graph'] as $item) {
                        if (($item['@type'] ?? null) === 'Product') {
                            yield from $this->parseJsonLdProductReviews($item);
                        }
                    }
                }

                // Direct Product type with reviews
                if (($data['@type'] ?? null) === 'Product' && isset($data['review'])) {
                    yield from $this->parseJsonLdProductReviews($data);
                }
            }
        } catch (\Exception $e) {
            Log::debug("AsdaProductReviewsExtractor: Failed to extract JSON-LD reviews: {$e->getMessage()}");
        }
    }

    /**
     * Parse reviews from a JSON-LD Product object.
     *
     * @param  array<string, mixed>  $product
     * @return Generator<ProductReview>
     */
    private function parseJsonLdProductReviews(array $product): Generator
    {
        if (! isset($product['review'])) {
            return;
        }

        $reviews = $product['review'];

        // Handle single review (not in array)
        if (isset($reviews['@type'])) {
            $reviews = [$reviews];
        }

        $index = 0;
        foreach ($reviews as $reviewData) {
            $review = $this->parseJsonLdReview($reviewData, $index);
            if ($review !== null) {
                yield $review;
                $index++;
            }
        }
    }

    /**
     * Parse a single review from JSON-LD data.
     *
     * @param  array<string, mixed>  $reviewData
     */
    private function parseJsonLdReview(array $reviewData, int $index): ?ProductReview
    {
        try {
            // Extract rating
            $rating = null;
            if (isset($reviewData['reviewRating']['ratingValue'])) {
                $rating = (float) $reviewData['reviewRating']['ratingValue'];
            } elseif (isset($reviewData['ratingValue'])) {
                $rating = (float) $reviewData['ratingValue'];
            }

            if ($rating === null) {
                return null;
            }

            // Extract body
            $body = $reviewData['reviewBody'] ?? $reviewData['description'] ?? null;
            if (empty($body)) {
                return null;
            }

            // Extract author
            $author = null;
            if (isset($reviewData['author'])) {
                $author = is_string($reviewData['author'])
                    ? $reviewData['author']
                    : ($reviewData['author']['name'] ?? null);
            }

            // Extract date
            $reviewDate = null;
            if (isset($reviewData['datePublished'])) {
                try {
                    $reviewDate = Carbon::parse($reviewData['datePublished']);
                } catch (\Exception $e) {
                    // Continue without date
                }
            }

            // Generate external ID
            $externalId = $reviewData['@id']
                ?? $reviewData['identifier']
                ?? 'asda-review-'.md5($body.$author).'-'.$index;

            return new ProductReview(
                externalId: $externalId,
                rating: $rating,
                author: $author,
                title: $reviewData['name'] ?? $reviewData['headline'] ?? null,
                body: $body,
                verifiedPurchase: $reviewData['verifiedPurchase'] ?? false,
                reviewDate: $reviewDate,
                helpfulCount: 0,
                metadata: [
                    'source' => 'json-ld',
                    'retailer' => 'asda',
                    'extracted_at' => now()->toIso8601String(),
                ],
            );
        } catch (\Exception $e) {
            Log::debug("AsdaProductReviewsExtractor: Failed to parse JSON-LD review: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Parse a single review element into a ProductReview DTO.
     */
    private function parseReview(Crawler $reviewNode, string $sourceUrl, int $index): ?ProductReview
    {
        try {
            // Extract review ID
            $reviewId = $reviewNode->attr('data-review-id')
                ?? $reviewNode->attr('id')
                ?? null;

            // Extract rating (out of 5)
            $rating = $this->extractRating($reviewNode);
            if ($rating === null) {
                Log::debug('AsdaProductReviewsExtractor: Could not extract rating for review');

                return null;
            }

            // Extract review body
            $body = $this->extractReviewBody($reviewNode);
            if (empty($body)) {
                Log::debug('AsdaProductReviewsExtractor: Could not extract body for review');

                return null;
            }

            // Generate ID if not found
            if ($reviewId === null) {
                $reviewId = 'asda-review-'.md5($sourceUrl.$body).'-'.$index;
            }

            // Extract optional fields
            $author = $this->extractAuthor($reviewNode);
            $title = $this->extractReviewTitle($reviewNode);
            $reviewDate = $this->extractReviewDate($reviewNode);
            $verifiedPurchase = $this->isVerifiedPurchase($reviewNode);
            $helpfulCount = $this->extractHelpfulCount($reviewNode);

            return new ProductReview(
                externalId: $reviewId,
                rating: $rating,
                author: $author,
                title: $title,
                body: $body,
                verifiedPurchase: $verifiedPurchase,
                reviewDate: $reviewDate,
                helpfulCount: $helpfulCount,
                metadata: [
                    'source_url' => $sourceUrl,
                    'extracted_at' => now()->toIso8601String(),
                    'retailer' => 'asda',
                ],
            );
        } catch (\Exception $e) {
            Log::debug("AsdaProductReviewsExtractor: Failed to parse review: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Extract rating from review element.
     */
    private function extractRating(Crawler $reviewNode): ?float
    {
        $selectors = [
            '[data-auto-id="review-rating"]',
            '.review-rating',
            '.star-rating',
            '.rating',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    // Try data attribute first
                    $dataRating = $element->first()->attr('data-rating');
                    if ($dataRating !== null) {
                        return (float) $dataRating;
                    }

                    // Try aria-label
                    $ariaLabel = $element->first()->attr('aria-label');
                    if ($ariaLabel !== null && preg_match('/([\d.]+)\s*(?:out of\s*5|stars?)/i', $ariaLabel, $matches)) {
                        return (float) $matches[1];
                    }

                    // Try text content
                    $text = $element->first()->text();
                    if (preg_match('/([\d.]+)\s*(?:out of\s*5|stars?|\/\s*5)/i', $text, $matches)) {
                        return (float) $matches[1];
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        // Try counting star elements
        try {
            $filledStars = $reviewNode->filter('.star-filled, .star-full, [data-star="filled"]');
            if ($filledStars->count() > 0) {
                return (float) $filledStars->count();
            }
        } catch (\Exception $e) {
            // Continue
        }

        return null;
    }

    /**
     * Extract review body text.
     */
    private function extractReviewBody(Crawler $reviewNode): ?string
    {
        $selectors = [
            '[data-auto-id="review-body"]',
            '.review-body',
            '.review-text',
            '.review-content',
            '.review-description',
            'p',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    if (! empty($text) && strlen($text) > 10) {
                        return $text;
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Extract review title.
     */
    private function extractReviewTitle(Crawler $reviewNode): ?string
    {
        $selectors = [
            '[data-auto-id="review-title"]',
            '.review-title',
            '.review-headline',
            'h3',
            'h4',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    if (! empty($text)) {
                        return $text;
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Extract author name.
     */
    private function extractAuthor(Crawler $reviewNode): ?string
    {
        $selectors = [
            '[data-auto-id="review-author"]',
            '.review-author',
            '.reviewer-name',
            '.author-name',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    if (! empty($text)) {
                        return $text;
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Extract review date.
     */
    private function extractReviewDate(Crawler $reviewNode): ?\DateTimeInterface
    {
        $selectors = [
            '[data-auto-id="review-date"]',
            '.review-date',
            'time',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    // Try datetime attribute first
                    $datetime = $element->first()->attr('datetime');
                    if ($datetime !== null) {
                        try {
                            return Carbon::parse($datetime);
                        } catch (\Exception $e) {
                            // Continue
                        }
                    }

                    // Try text content
                    $text = trim($element->first()->text());

                    // Format: "15 January 2024" or "15/01/2024" or "Jan 15, 2024"
                    if (preg_match('/(\d{1,2}(?:st|nd|rd|th)?\s+\w+\s+\d{4})/i', $text, $matches)) {
                        try {
                            return Carbon::parse($matches[1]);
                        } catch (\Exception $e) {
                            // Continue
                        }
                    }

                    // Try direct parsing
                    try {
                        return Carbon::parse($text);
                    } catch (\Exception $e) {
                        // Continue
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Check if review is from a verified purchase.
     */
    private function isVerifiedPurchase(Crawler $reviewNode): bool
    {
        $selectors = [
            '[data-auto-id="verified-purchase"]',
            '.verified-purchase',
            '.verified-badge',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    return true;
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        // Check text content for verified indicators
        try {
            $text = strtolower($reviewNode->text());
            if (str_contains($text, 'verified purchase') || str_contains($text, 'verified buyer')) {
                return true;
            }
        } catch (\Exception $e) {
            // Continue
        }

        return false;
    }

    /**
     * Extract helpful vote count.
     */
    private function extractHelpfulCount(Crawler $reviewNode): int
    {
        $selectors = [
            '[data-auto-id="helpful-count"]',
            '.helpful-count',
            '.vote-count',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    $text = strtolower($element->first()->text());

                    // Format: "42 people found this helpful"
                    if (preg_match('/(\d+)\s*(?:people|person)/i', $text, $matches)) {
                        return (int) $matches[1];
                    }
                    if (str_contains($text, 'one person')) {
                        return 1;
                    }

                    // Simple number
                    if (preg_match('/^(\d+)$/', trim($element->first()->text()), $matches)) {
                        return (int) $matches[1];
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return 0;
    }

    /**
     * Build a reviews page URL for a given product ID.
     */
    public static function buildReviewsUrl(string $productId, int $page = 1): string
    {
        // Asda may use pagination parameters or AJAX for reviews
        // This is a best-guess URL structure
        return "https://groceries.asda.com/product/{$productId}/reviews?page={$page}";
    }
}
