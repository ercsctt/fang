<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Waitrose;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductReview;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class WaitroseProductReviewsExtractor implements ExtractorInterface
{
    /**
     * Extract reviews from Waitrose product pages.
     *
     * Reviews may be found in:
     * - JSON-LD structured data
     * - DOM review sections
     * - Bazaarvoice widget (common for retailers)
     */
    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        $reviewsExtracted = 0;

        // Try JSON-LD structured data first (most reliable)
        foreach ($this->extractFromJsonLd($crawler, $url) as $review) {
            $reviewsExtracted++;
            yield $review;
        }

        // Fall back to DOM-based extraction if no JSON-LD reviews found
        if ($reviewsExtracted === 0) {
            foreach ($this->extractFromDom($crawler, $url) as $review) {
                $reviewsExtracted++;
                yield $review;
            }
        }

        Log::info("WaitroseProductReviewsExtractor: Extracted {$reviewsExtracted} reviews from {$url}");
    }

    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'waitrose.com')) {
            // Handle product URLs: /ecom/products/{product-slug}/{product-id}
            return (bool) preg_match('/\/ecom\/products\/[a-z0-9-]+\/[a-z0-9-]+/i', $url);
        }

        return false;
    }

    /**
     * Extract reviews from JSON-LD structured data.
     *
     * @return Generator<ProductReview>
     */
    private function extractFromJsonLd(Crawler $crawler, string $url): Generator
    {
        $reviews = [];

        try {
            $scripts = $crawler->filter('script[type="application/ld+json"]');

            $scripts->each(function (Crawler $node) use (&$reviews) {
                $json = json_decode($node->text(), true);
                if ($json === null) {
                    return;
                }

                // Handle @graph format
                if (isset($json['@graph']) && is_array($json['@graph'])) {
                    foreach ($json['@graph'] as $item) {
                        if ($this->isProductWithReviews($item)) {
                            $reviews = $item['review'] ?? [];
                        }
                    }
                }

                // Handle direct Product type
                if ($this->isProductWithReviews($json)) {
                    $reviews = $json['review'] ?? [];
                }
            });

            if (empty($reviews)) {
                return;
            }

            foreach ($reviews as $index => $reviewData) {
                $review = $this->parseJsonLdReview($reviewData, $url, $index);
                if ($review !== null) {
                    yield $review;
                }
            }
        } catch (\Exception $e) {
            Log::debug("WaitroseProductReviewsExtractor: JSON-LD extraction failed: {$e->getMessage()}");
        }
    }

    /**
     * Check if JSON-LD item is a Product with reviews.
     *
     * @param  array<string, mixed>  $item
     */
    private function isProductWithReviews(array $item): bool
    {
        $type = $item['@type'] ?? '';

        return ($type === 'Product' || $type === ['Product'])
            && isset($item['review'])
            && is_array($item['review']);
    }

    /**
     * Parse a single review from JSON-LD format.
     *
     * @param  array<string, mixed>  $reviewData
     */
    private function parseJsonLdReview(array $reviewData, string $url, int $index): ?ProductReview
    {
        // Extract rating
        $rating = null;
        if (isset($reviewData['reviewRating'])) {
            $rating = (float) ($reviewData['reviewRating']['ratingValue'] ?? 0);
        } elseif (isset($reviewData['ratingValue'])) {
            $rating = (float) $reviewData['ratingValue'];
        }

        if ($rating === null || $rating <= 0) {
            return null;
        }

        // Extract author
        $author = null;
        if (isset($reviewData['author'])) {
            $author = is_array($reviewData['author'])
                ? ($reviewData['author']['name'] ?? null)
                : $reviewData['author'];
        }

        // Extract body
        $body = $reviewData['reviewBody'] ?? $reviewData['description'] ?? '';
        if (empty($body)) {
            return null;
        }

        // Extract date
        $reviewDate = null;
        if (isset($reviewData['datePublished'])) {
            try {
                $reviewDate = new DateTimeImmutable($reviewData['datePublished']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        // Generate external ID
        $externalId = $reviewData['@id']
            ?? $reviewData['identifier']
            ?? $this->generateReviewId($url, $author, $body, $index);

        return new ProductReview(
            externalId: $externalId,
            rating: $rating,
            author: $author,
            title: $reviewData['name'] ?? $reviewData['headline'] ?? null,
            body: $body,
            verifiedPurchase: $reviewData['verifiedPurchase'] ?? false,
            reviewDate: $reviewDate,
            helpfulCount: (int) ($reviewData['upvoteCount'] ?? 0),
            metadata: [
                'source' => 'json-ld',
                'source_url' => $url,
                'extracted_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Extract reviews from DOM elements.
     *
     * @return Generator<ProductReview>
     */
    private function extractFromDom(Crawler $crawler, string $url): Generator
    {
        // Waitrose-specific review selectors
        $reviewSelectors = [
            '[data-test="review-item"]',
            '[data-testid="review-item"]',
            '.bv-content-item',
            '.bv-content-review',
            '[data-bv-show="reviews"] .bv-review',
            '.review-item',
            '.customer-review',
            '[data-review]',
            '.reviews-list .review',
            '.product-review',
            '[itemtype*="Review"]',
        ];

        foreach ($reviewSelectors as $selector) {
            try {
                $reviews = $crawler->filter($selector);
                if ($reviews->count() > 0) {
                    $index = 0;
                    foreach ($reviews as $reviewNode) {
                        $review = $this->parseDomReview(new Crawler($reviewNode), $url, $index);
                        if ($review !== null) {
                            yield $review;
                            $index++;
                        }
                    }

                    break; // Found reviews, stop trying other selectors
                }
            } catch (\Exception $e) {
                Log::debug("WaitroseProductReviewsExtractor: DOM selector {$selector} failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Parse a single review from DOM element.
     */
    private function parseDomReview(Crawler $node, string $url, int $index): ?ProductReview
    {
        // Extract rating
        $rating = $this->extractRatingFromDom($node);
        if ($rating === null || $rating <= 0) {
            return null;
        }

        // Extract body
        $body = $this->extractTextFromSelectors($node, [
            '[data-test="review-body"]',
            '[data-testid="review-body"]',
            '.review-body',
            '.review-text',
            '.review-content',
            '.bv-content-summary-body-text',
            '[itemprop="reviewBody"]',
            '.description',
            'p',
        ]);

        if (empty($body)) {
            return null;
        }

        // Extract author
        $author = $this->extractTextFromSelectors($node, [
            '[data-test="review-author"]',
            '[data-testid="review-author"]',
            '.review-author',
            '.author-name',
            '.bv-author',
            '[itemprop="author"]',
            '.reviewer-name',
        ]);

        // Extract title
        $title = $this->extractTextFromSelectors($node, [
            '[data-test="review-title"]',
            '[data-testid="review-title"]',
            '.review-title',
            '.review-headline',
            '.bv-content-title',
            '[itemprop="name"]',
            'h3',
            'h4',
        ]);

        // Extract date
        $reviewDate = $this->extractDateFromDom($node);

        // Extract verified purchase indicator
        $verifiedPurchase = $this->isVerifiedPurchase($node);

        // Extract helpful count
        $helpfulCount = $this->extractHelpfulCount($node);

        // Generate external ID
        $externalId = $node->attr('data-review-id')
            ?? $node->attr('data-bv-content-id')
            ?? $node->attr('id')
            ?? $this->generateReviewId($url, $author, $body, $index);

        return new ProductReview(
            externalId: $externalId,
            rating: $rating,
            author: $author,
            title: $title,
            body: $body,
            verifiedPurchase: $verifiedPurchase,
            reviewDate: $reviewDate,
            helpfulCount: $helpfulCount,
            metadata: [
                'source' => 'dom',
                'source_url' => $url,
                'extracted_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Extract rating from DOM element.
     */
    private function extractRatingFromDom(Crawler $node): ?float
    {
        // Try data attributes on node itself first
        $ratingAttrs = ['data-rating', 'data-score', 'data-stars'];
        foreach ($ratingAttrs as $attr) {
            $value = $node->attr($attr);
            if ($value !== null && is_numeric($value)) {
                return (float) $value;
            }
        }

        // Try Waitrose specific rating selectors
        $ratingSelectors = [
            '[data-test="review-rating"]',
            '[data-testid="review-rating"]',
            '.bv-rating-ratio-number',
            '[data-rating]',
            '[data-score]',
            '[data-stars]',
            '.review-rating',
            '.rating',
            '.star-rating',
        ];
        foreach ($ratingSelectors as $selector) {
            try {
                $ratingElement = $node->filter($selector);
                if ($ratingElement->count() > 0) {
                    foreach ($ratingAttrs as $attr) {
                        $value = $ratingElement->first()->attr($attr);
                        if ($value !== null && is_numeric($value)) {
                            return (float) $value;
                        }
                    }

                    // Try aria-label for accessibility-friendly rating
                    $ariaLabel = $ratingElement->first()->attr('aria-label');
                    if ($ariaLabel !== null && preg_match('/(\d+(?:\.\d+)?)\s*(?:\/\s*5|out of 5|stars?)/i', $ariaLabel, $matches)) {
                        return (float) $matches[1];
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        // Try meta/span with itemprop
        try {
            $ratingNode = $node->filter('[itemprop="ratingValue"]');
            if ($ratingNode->count() > 0) {
                $value = $ratingNode->attr('content') ?? $ratingNode->text();
                if (is_numeric($value)) {
                    return (float) $value;
                }
            }
        } catch (\Exception $e) {
            // Continue
        }

        // Try counting filled stars
        try {
            $filledStars = $node->filter('.star-filled, .star-full, .fa-star:not(.fa-star-o), .icon-star-filled, .star.active, .bv-glyph-star-full');
            if ($filledStars->count() > 0) {
                return (float) $filledStars->count();
            }
        } catch (\Exception $e) {
            // Continue
        }

        // Try Bazaarvoice style rating (percentage width)
        try {
            $ratingNode = $node->filter('.bv-rating-stars-on, .star-rating');
            if ($ratingNode->count() > 0) {
                $style = $ratingNode->attr('style');
                if ($style && preg_match('/width:\s*(\d+(?:\.\d+)?)\s*%/', $style, $matches)) {
                    // Convert percentage to 5-star rating
                    return round((float) $matches[1] / 20, 1);
                }
            }
        } catch (\Exception $e) {
            // Continue
        }

        // Try text-based rating
        $textSelectors = ['.rating', '.stars', '.review-rating'];
        foreach ($textSelectors as $selector) {
            try {
                $ratingNode = $node->filter($selector);
                if ($ratingNode->count() > 0) {
                    $text = $ratingNode->text();
                    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:\/\s*5|out of 5|stars?)/i', $text, $matches)) {
                        return (float) $matches[1];
                    }
                    if (preg_match('/^(\d+(?:\.\d+)?)$/', trim($text), $matches)) {
                        return (float) $matches[1];
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Extract text from first matching selector.
     *
     * @param  array<string>  $selectors
     */
    private function extractTextFromSelectors(Crawler $node, array $selectors): ?string
    {
        foreach ($selectors as $selector) {
            try {
                $element = $node->filter($selector);
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
     * Extract review date from DOM element.
     */
    private function extractDateFromDom(Crawler $node): ?\DateTimeInterface
    {
        $dateSelectors = [
            '[data-test="review-date"]',
            '[data-testid="review-date"]',
            '[itemprop="datePublished"]',
            '.review-date',
            '.date',
            'time',
            '.bv-content-datetime',
            '.bv-content-datetime-stamp',
        ];

        foreach ($dateSelectors as $selector) {
            try {
                $dateNode = $node->filter($selector);
                if ($dateNode->count() > 0) {
                    $dateStr = $dateNode->attr('datetime')
                        ?? $dateNode->attr('content')
                        ?? $dateNode->text();

                    if ($dateStr) {
                        return new DateTimeImmutable($dateStr);
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
    private function isVerifiedPurchase(Crawler $node): bool
    {
        $verifiedSelectors = [
            '[data-test="verified-purchase"]',
            '[data-testid="verified-purchase"]',
            '.verified-purchase',
            '.verified-buyer',
            '[data-verified="true"]',
            '.badge-verified',
            '.verified',
            '.bv-content-badges-verified',
        ];

        foreach ($verifiedSelectors as $selector) {
            try {
                if ($node->filter($selector)->count() > 0) {
                    return true;
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        // Check for verified text
        try {
            $html = $node->html();
            if (preg_match('/verified\s+(purchase|buyer|owner)/i', $html)) {
                return true;
            }
        } catch (\Exception $e) {
            // Continue
        }

        return false;
    }

    /**
     * Extract helpful count from review.
     */
    private function extractHelpfulCount(Crawler $node): int
    {
        $helpfulSelectors = [
            '[data-test="helpful-count"]',
            '[data-testid="helpful-count"]',
            '.helpful-count',
            '.vote-count',
            '[data-helpful-count]',
            '.upvotes',
            '.helpful-votes',
            '.bv-content-feedback-vote-positive',
        ];

        foreach ($helpfulSelectors as $selector) {
            try {
                $helpfulNode = $node->filter($selector);
                if ($helpfulNode->count() > 0) {
                    $text = $helpfulNode->attr('data-helpful-count') ?? $helpfulNode->text();
                    if (preg_match('/(\d+)/', $text, $matches)) {
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
     * Generate a unique review ID based on content.
     */
    private function generateReviewId(string $url, ?string $author, string $body, int $index): string
    {
        $hash = md5($body.$author);

        return "waitrose-review-{$hash}-{$index}";
    }

    /**
     * Build a URL for fetching reviews for a product.
     */
    public static function buildReviewsUrl(string $productSlug, string $productId, int $page = 1): string
    {
        return "https://www.waitrose.com/ecom/products/{$productSlug}/{$productId}/reviews?page={$page}";
    }
}
