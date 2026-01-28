<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Asda;

use App\Crawler\Extractors\Concerns\BaseProductReviewsExtractor;
use Carbon\Carbon;
use DateTimeInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Asda product reviews extractor.
 *
 * Asda uses a slightly different JSON-LD review extraction pattern
 * that yields reviews one at a time from within the JSON-LD extraction method.
 */
class AsdaProductReviewsExtractor extends BaseProductReviewsExtractor
{
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

    protected function getExtractorName(): string
    {
        return 'AsdaProductReviewsExtractor';
    }

    protected function getRetailerSlug(): string
    {
        return 'asda';
    }

    protected function getReviewSelectors(): array
    {
        return [
            '[data-auto-id="review"]',
            '.review-item',
            '.customer-review',
            '.product-review',
            '[data-testid="review"]',
        ];
    }

    protected function getReviewBodySelectors(): array
    {
        return [
            '[data-auto-id="review-body"]',
            '.review-body',
            '.review-text',
            '.review-content',
            '.review-description',
            'p',
        ];
    }

    protected function getReviewAuthorSelectors(): array
    {
        return [
            '[data-auto-id="review-author"]',
            '.review-author',
            '.reviewer-name',
            '.author-name',
        ];
    }

    protected function getReviewTitleSelectors(): array
    {
        return [
            '[data-auto-id="review-title"]',
            '.review-title',
            '.review-headline',
            'h3',
            'h4',
        ];
    }

    protected function getRatingSelectors(): array
    {
        return [
            '[data-auto-id="review-rating"]',
            '.review-rating',
            '.star-rating',
            '.rating',
        ];
    }

    protected function getDateSelectors(): array
    {
        return [
            '[data-auto-id="review-date"]',
            '.review-date',
            'time',
        ];
    }

    protected function getVerifiedPurchaseSelectors(): array
    {
        return [
            '[data-auto-id="verified-purchase"]',
            '.verified-purchase',
            '.verified-badge',
        ];
    }

    protected function getHelpfulCountSelectors(): array
    {
        return [
            '[data-auto-id="helpful-count"]',
            '.helpful-count',
            '.vote-count',
        ];
    }

    protected function getFilledStarSelector(): string
    {
        return '.star-filled, .star-full, [data-star="filled"]';
    }

    /**
     * Override to use Carbon for date parsing with Asda-specific date formats.
     */
    protected function extractDateFromDom(Crawler $node): ?DateTimeInterface
    {
        foreach ($this->getDateSelectors() as $selector) {
            try {
                $element = $node->filter($selector);
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
     * Override to include star counting for Asda-specific star elements.
     */
    protected function extractRatingFromDom(Crawler $node): ?float
    {
        // Try parent method first
        $rating = parent::extractRatingFromDom($node);
        if ($rating !== null) {
            return $rating;
        }

        // Try Asda-specific aria-label pattern
        foreach ($this->getRatingSelectors() as $selector) {
            try {
                $element = $node->filter($selector);
                if ($element->count() > 0) {
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

        return null;
    }

    /**
     * Override to include Asda-specific helpful count parsing.
     */
    protected function extractHelpfulCount(Crawler $node): int
    {
        foreach ($this->getHelpfulCountSelectors() as $selector) {
            try {
                $element = $node->filter($selector);
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
