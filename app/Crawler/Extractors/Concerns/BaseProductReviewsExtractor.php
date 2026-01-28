<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Concerns;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductReview;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Base class for product reviews extractors with shared logic.
 *
 * This abstract class consolidates common patterns from all ProductReviewsExtractor
 * classes, including:
 * - JSON-LD review extraction (try first, most reliable)
 * - DOM fallback extraction
 * - Common helper methods (extractTextFromSelectors, extractRatingFromDom, etc.)
 * - CAPTCHA/blocked page detection
 *
 * Child classes must implement canHandle() and provide store-specific selectors
 * via the abstract getter methods.
 */
abstract class BaseProductReviewsExtractor implements ExtractorInterface
{
    use SelectsElements;

    /**
     * Extract reviews from HTML content.
     *
     * Flow: Try JSON-LD first (most reliable), then fallback to DOM extraction.
     */
    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Check for blocked page (CAPTCHA, bot detection, etc.)
        if ($this->isBlockedPage($crawler, $html)) {
            Log::warning("{$this->getExtractorName()}: Blocked/CAPTCHA page detected at {$url}");

            return;
        }

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

        Log::info("{$this->getExtractorName()}: Extracted {$reviewsExtracted} reviews from {$url}");
    }

    /**
     * Check if the page is blocked or shows a CAPTCHA.
     *
     * Override in child classes for store-specific detection patterns.
     */
    protected function isBlockedPage(Crawler $crawler, string $html): bool
    {
        // Common CAPTCHA/block indicators
        $blockIndicators = ['captcha', 'robot check', 'access denied', 'blocked'];

        $lowerHtml = strtolower($html);
        foreach ($blockIndicators as $indicator) {
            if (str_contains($lowerHtml, $indicator)) {
                return true;
            }
        }

        $titleNode = $this->selectFirst(
            $crawler,
            ['title'],
            'Blocked page title',
            fn (Crawler $node) => trim($node->text()) !== ''
        );

        if ($titleNode !== null) {
            $title = strtolower($titleNode->text());
            $titleBlockIndicators = ['sorry', 'robot', 'blocked', 'captcha', 'access denied'];
            foreach ($titleBlockIndicators as $indicator) {
                if (str_contains($title, $indicator)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract reviews from JSON-LD structured data.
     *
     * @return Generator<ProductReview>
     */
    protected function extractFromJsonLd(Crawler $crawler, string $url): Generator
    {
        $reviews = [];

        $scripts = $this->selectAll(
            $crawler,
            ['script[type="application/ld+json"]'],
            'JSON-LD reviews'
        );

        if ($scripts === null) {
            return;
        }

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
    }

    /**
     * Check if JSON-LD item is a Product with reviews.
     *
     * @param  array<string, mixed>  $item
     */
    protected function isProductWithReviews(array $item): bool
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
    protected function parseJsonLdReview(array $reviewData, string $url, int $index): ?ProductReview
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
                'retailer' => $this->getRetailerSlug(),
                'extracted_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Extract reviews from DOM elements.
     *
     * @return Generator<ProductReview>
     */
    protected function extractFromDom(Crawler $crawler, string $url): Generator
    {
        $reviews = $this->selectAll($crawler, $this->getReviewSelectors(), 'Reviews');

        if ($reviews === null) {
            return;
        }

        $index = 0;
        foreach ($reviews as $reviewNode) {
            $review = $this->parseDomReview(new Crawler($reviewNode), $url, $index);
            if ($review !== null) {
                yield $review;
                $index++;
            }
        }
    }

    /**
     * Parse a single review from DOM element.
     */
    protected function parseDomReview(Crawler $node, string $url, int $index): ?ProductReview
    {
        // Extract rating
        $rating = $this->extractRatingFromDom($node);
        if ($rating === null || $rating <= 0) {
            return null;
        }

        // Extract body
        $body = $this->extractTextFromSelectors($node, $this->getReviewBodySelectors());
        if (empty($body)) {
            return null;
        }

        // Extract author
        $author = $this->extractTextFromSelectors($node, $this->getReviewAuthorSelectors());

        // Extract title
        $title = $this->extractTextFromSelectors($node, $this->getReviewTitleSelectors());

        // Extract date
        $reviewDate = $this->extractDateFromDom($node);

        // Extract verified purchase indicator
        $verifiedPurchase = $this->isVerifiedPurchase($node);

        // Extract helpful count
        $helpfulCount = $this->extractHelpfulCount($node);

        // Generate external ID
        $externalId = $this->extractExternalIdFromDom($node)
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
                'retailer' => $this->getRetailerSlug(),
                'extracted_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Extract external ID from DOM node.
     *
     * Override in child classes for store-specific ID extraction.
     */
    protected function extractExternalIdFromDom(Crawler $node): ?string
    {
        return $node->attr('data-review-id')
            ?? $node->attr('id')
            ?? null;
    }

    /**
     * Extract rating from DOM element.
     */
    protected function extractRatingFromDom(Crawler $node): ?float
    {
        $ratingAttrs = ['data-rating', 'data-score', 'data-stars'];
        foreach ($ratingAttrs as $attr) {
            $value = $node->attr($attr);
            if ($value !== null && is_numeric($value)) {
                return (float) $value;
            }
        }

        $ratingElement = $this->selectFirst(
            $node,
            $this->getRatingSelectors(),
            'Rating',
            fn (Crawler $candidate) => $this->extractRatingValueFromElement($candidate) !== null
        );

        if ($ratingElement !== null) {
            $value = $this->extractRatingValueFromElement($ratingElement);
            if ($value !== null) {
                return $value;
            }
        }

        $ratingNode = $this->selectFirst($node, ['[itemprop="ratingValue"]'], 'Rating');
        if ($ratingNode !== null) {
            $value = $ratingNode->attr('content') ?? $ratingNode->text();
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        $filledStars = $this->selectAll($node, [$this->getFilledStarSelector()], 'Rating');
        if ($filledStars !== null) {
            return (float) $filledStars->count();
        }

        $ratingNode = $this->selectFirst(
            $node,
            ['.rating-stars, .star-rating, .bv-rating-stars-on'],
            'Rating',
            fn (Crawler $candidate) => $this->extractRatingFromStyle($candidate) !== null
        );

        if ($ratingNode !== null) {
            $value = $this->extractRatingFromStyle($ratingNode);
            if ($value !== null) {
                return $value;
            }
        }

        $ratingNode = $this->selectFirst(
            $node,
            ['.rating', '.stars', '.review-rating'],
            'Rating',
            fn (Crawler $candidate) => $this->extractRatingFromText($candidate->text()) !== null
        );

        if ($ratingNode !== null) {
            $value = $this->extractRatingFromText($ratingNode->text());
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Extract text from first matching selector.
     *
     * @param  array<string>  $selectors
     */
    protected function extractTextFromSelectors(Crawler $node, array $selectors): ?string
    {
        $element = $this->selectFirst(
            $node,
            $selectors,
            'Review text',
            fn (Crawler $candidate) => trim($candidate->text()) !== ''
        );

        return $element !== null ? trim($element->text()) : null;
    }

    /**
     * Extract review date from DOM element.
     */
    protected function extractDateFromDom(Crawler $node): ?DateTimeInterface
    {
        $dateNode = $this->selectFirst(
            $node,
            $this->getDateSelectors(),
            'Review date',
            fn (Crawler $candidate) => $this->parseDateFromNode($candidate) !== null
        );

        return $dateNode !== null ? $this->parseDateFromNode($dateNode) : null;
    }

    /**
     * Check if review is from a verified purchase.
     */
    protected function isVerifiedPurchase(Crawler $node): bool
    {
        if ($this->selectFirst($node, $this->getVerifiedPurchaseSelectors(), 'Verified purchase') !== null) {
            return true;
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
    protected function extractHelpfulCount(Crawler $node): int
    {
        $helpfulNode = $this->selectFirst(
            $node,
            $this->getHelpfulCountSelectors(),
            'Helpful count',
            fn (Crawler $candidate) => $this->extractHelpfulCountFromNode($candidate) !== null
        );

        return $helpfulNode !== null ? $this->extractHelpfulCountFromNode($helpfulNode) : 0;
    }

    protected function extractRatingValueFromElement(Crawler $ratingElement): ?float
    {
        $ratingAttrs = ['data-rating', 'data-score', 'data-stars'];
        foreach ($ratingAttrs as $attr) {
            $value = $ratingElement->attr($attr);
            if ($value !== null && is_numeric($value)) {
                return (float) $value;
            }
        }

        $ariaLabel = $ratingElement->attr('aria-label');
        if ($ariaLabel !== null && preg_match('/(\d+(?:\.\d+)?)\s*(?:\/\s*5|out of 5|stars?)/i', $ariaLabel, $matches)) {
            return (float) $matches[1];
        }

        return $this->extractRatingFromText($ratingElement->text());
    }

    protected function extractRatingFromText(string $text): ?float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:\/\s*5|out of 5|stars?)/i', $text, $matches)) {
            return (float) $matches[1];
        }

        if (preg_match('/^(\d+(?:\.\d+)?)$/', trim($text), $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    protected function extractRatingFromStyle(Crawler $ratingNode): ?float
    {
        $style = $ratingNode->attr('style');
        if ($style && preg_match('/width:\s*(\d+(?:\.\d+)?)\s*%/', $style, $matches)) {
            return round((float) $matches[1] / 20, 1);
        }

        return null;
    }

    protected function parseDateFromNode(Crawler $dateNode): ?DateTimeImmutable
    {
        $dateStr = $dateNode->attr('datetime')
            ?? $dateNode->attr('content')
            ?? $dateNode->text();

        if (! $dateStr) {
            return null;
        }

        try {
            return new DateTimeImmutable($dateStr);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function extractHelpfulCountFromNode(Crawler $helpfulNode): ?int
    {
        $text = $helpfulNode->attr('data-helpful-count') ?? $helpfulNode->text();
        if (preg_match('/(\d+)/', $text, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Generate a unique review ID based on content.
     */
    protected function generateReviewId(string $url, ?string $author, string $body, int $index): string
    {
        $hash = md5($url.$author.$body);

        return "{$this->getRetailerSlug()}-review-{$hash}-{$index}";
    }

    // ========================================
    // Abstract methods - must be implemented by child classes
    // ========================================

    /**
     * Get the extractor name for logging.
     *
     * Example: "TescoProductReviewsExtractor"
     */
    abstract protected function getExtractorName(): string;

    /**
     * Get the retailer slug for review ID generation.
     *
     * Example: "tesco", "sainsburys", "amazon-uk"
     */
    abstract protected function getRetailerSlug(): string;

    /**
     * Get CSS selectors for finding review containers.
     *
     * @return array<string>
     */
    abstract protected function getReviewSelectors(): array;

    // ========================================
    // Optional methods - can be overridden by child classes for custom selectors
    // ========================================

    /**
     * Get CSS selectors for extracting review body text.
     *
     * @return array<string>
     */
    protected function getReviewBodySelectors(): array
    {
        return [
            '.review-body',
            '.review-text',
            '.review-content',
            '[itemprop="reviewBody"]',
            '.description',
            'p',
        ];
    }

    /**
     * Get CSS selectors for extracting review author.
     *
     * @return array<string>
     */
    protected function getReviewAuthorSelectors(): array
    {
        return [
            '.review-author',
            '.author-name',
            '[itemprop="author"]',
            '.reviewer-name',
        ];
    }

    /**
     * Get CSS selectors for extracting review title.
     *
     * @return array<string>
     */
    protected function getReviewTitleSelectors(): array
    {
        return [
            '.review-title',
            '.review-headline',
            '[itemprop="name"]',
            'h3',
            'h4',
        ];
    }

    /**
     * Get CSS selectors for extracting rating.
     *
     * @return array<string>
     */
    protected function getRatingSelectors(): array
    {
        return [
            '[data-rating]',
            '[data-score]',
            '[data-stars]',
            '.review-rating',
            '.rating',
            '.star-rating',
        ];
    }

    /**
     * Get CSS selectors for extracting review date.
     *
     * @return array<string>
     */
    protected function getDateSelectors(): array
    {
        return [
            '[itemprop="datePublished"]',
            '.review-date',
            '.date',
            'time',
        ];
    }

    /**
     * Get CSS selectors for detecting verified purchase.
     *
     * @return array<string>
     */
    protected function getVerifiedPurchaseSelectors(): array
    {
        return [
            '.verified-purchase',
            '.verified-buyer',
            '[data-verified="true"]',
            '.badge-verified',
            '.verified',
        ];
    }

    /**
     * Get CSS selectors for extracting helpful count.
     *
     * @return array<string>
     */
    protected function getHelpfulCountSelectors(): array
    {
        return [
            '.helpful-count',
            '.vote-count',
            '[data-helpful-count]',
            '.upvotes',
            '.helpful-votes',
        ];
    }

    /**
     * Get CSS selector for filled star elements.
     */
    protected function getFilledStarSelector(): string
    {
        return '.star-filled, .star-full, .fa-star:not(.fa-star-o), .icon-star-filled, .star.active';
    }
}
