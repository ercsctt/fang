<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Amazon;

use App\Crawler\Extractors\Concerns\BaseProductReviewsExtractor;
use Carbon\Carbon;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Amazon product reviews extractor.
 *
 * Amazon doesn't typically include reviews in JSON-LD structured data,
 * so this extractor primarily uses DOM-based extraction with Amazon-specific
 * selectors and CAPTCHA detection.
 */
class AmazonProductReviewsExtractor extends BaseProductReviewsExtractor
{
    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'amazon.co.uk')) {
            // Handle product pages (reviews embedded)
            if (preg_match('/\/dp\/[A-Z0-9]{10}(?:\/|$|\?)/i', $url)) {
                return true;
            }
            // Handle dedicated review pages
            if (str_contains($url, '/product-reviews/') || str_contains($url, '/customer-reviews/')) {
                return true;
            }
        }

        return false;
    }

    protected function getExtractorName(): string
    {
        return 'AmazonProductReviewsExtractor';
    }

    protected function getRetailerSlug(): string
    {
        return 'amazon-uk';
    }

    /**
     * Override extract to skip JSON-LD (Amazon doesn't use it for reviews)
     * and add pagination detection.
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

        // Amazon reviews are DOM-based only
        foreach ($this->extractFromDom($crawler, $url) as $review) {
            $reviewsExtracted++;
            yield $review;
        }

        // Extract pagination info for metadata
        $nextPageUrl = $this->extractNextPageUrl($crawler);

        Log::info("{$this->getExtractorName()}: Extracted {$reviewsExtracted} reviews from {$url}".
            ($nextPageUrl ? ' (more pages available)' : ''));
    }

    protected function getReviewSelectors(): array
    {
        return [
            '#cm-cr-dp-review-list .review',
            '#customer_review_foreign .review',
            '[data-hook="review"]',
            '.review',
        ];
    }

    protected function getReviewBodySelectors(): array
    {
        return [
            '[data-hook="review-body"] span',
            '.review-text-content span',
            '.review-text span',
            '.review-body',
            '.reviewText',
        ];
    }

    protected function getReviewAuthorSelectors(): array
    {
        return [
            '.a-profile-name',
            '[data-hook="review-author"] .a-profile-name',
            '.author',
            '.reviewer-name',
        ];
    }

    protected function getReviewTitleSelectors(): array
    {
        return [
            '[data-hook="review-title"] span:not(.a-icon-alt)',
            '.review-title-content span',
            '.review-title span',
            '.a-text-bold',
        ];
    }

    protected function getRatingSelectors(): array
    {
        return [
            '[data-hook="review-star-rating"] .a-icon-alt',
            '.review-rating .a-icon-alt',
            '.a-icon-star .a-icon-alt',
            'i[data-hook="review-star-rating"]',
        ];
    }

    protected function getDateSelectors(): array
    {
        return [
            '[data-hook="review-date"]',
            '.review-date',
        ];
    }

    protected function getVerifiedPurchaseSelectors(): array
    {
        return [
            '[data-hook="avp-badge"]',
            '.avp-badge',
            '.a-color-state',
        ];
    }

    protected function getHelpfulCountSelectors(): array
    {
        return [
            '[data-hook="helpful-vote-statement"]',
            '.cr-vote-text',
        ];
    }

    protected function getFilledStarSelector(): string
    {
        return '[class*="a-star-"]';
    }

    /**
     * Override to extract rating from Amazon-specific format.
     */
    protected function extractRatingFromDom(Crawler $node): ?float
    {
        // Try Amazon-specific selectors first
        foreach ($this->getRatingSelectors() as $selector) {
            try {
                $element = $node->filter($selector);
                if ($element->count() > 0) {
                    $text = $element->first()->text();
                    // Format: "4.0 out of 5 stars" or "4 out of 5 stars"
                    if (preg_match('/([\d.]+)\s*out of\s*5/i', $text, $matches)) {
                        return (float) $matches[1];
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        // Try class-based rating (e.g., a-star-4)
        try {
            $starIcon = $node->filter('[class*="a-star-"]');
            if ($starIcon->count() > 0) {
                $classes = $starIcon->first()->attr('class') ?? '';
                if (preg_match('/a-star-(\d)/i', $classes, $matches)) {
                    return (float) $matches[1];
                }
            }
        } catch (\Exception $e) {
            // Continue
        }

        return null;
    }

    /**
     * Override to extract date from Amazon-specific format.
     */
    protected function extractDateFromDom(Crawler $node): ?DateTimeInterface
    {
        foreach ($this->getDateSelectors() as $selector) {
            try {
                $element = $node->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());

                    // Format: "Reviewed in the United Kingdom on 15 January 2024"
                    if (preg_match('/on\s+(\d{1,2}\s+\w+\s+\d{4})/i', $text, $matches)) {
                        try {
                            return Carbon::parse($matches[1]);
                        } catch (\Exception $e) {
                            Log::debug("AmazonProductReviewsExtractor: Could not parse date: {$matches[1]}");
                        }
                    }

                    // Alternative format: "15 January 2024"
                    if (preg_match('/(\d{1,2}\s+\w+\s+\d{4})/i', $text, $matches)) {
                        try {
                            return Carbon::parse($matches[1]);
                        } catch (\Exception $e) {
                            Log::debug("AmazonProductReviewsExtractor: Could not parse date: {$matches[1]}");
                        }
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Override to check for Amazon-specific verified purchase text.
     */
    protected function isVerifiedPurchase(Crawler $node): bool
    {
        foreach ($this->getVerifiedPurchaseSelectors() as $selector) {
            try {
                $element = $node->filter($selector);
                if ($element->count() > 0) {
                    $text = strtolower($element->first()->text());
                    if (str_contains($text, 'verified purchase') || str_contains($text, 'verified')) {
                        return true;
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return false;
    }

    /**
     * Override to parse Amazon-specific helpful count format.
     */
    protected function extractHelpfulCount(Crawler $node): int
    {
        foreach ($this->getHelpfulCountSelectors() as $selector) {
            try {
                $element = $node->filter($selector);
                if ($element->count() > 0) {
                    $text = strtolower($element->first()->text());

                    // Format: "42 people found this helpful" or "One person found this helpful"
                    if (preg_match('/(\d+)\s*(?:people|person)/i', $text, $matches)) {
                        return (int) $matches[1];
                    }
                    if (str_contains($text, 'one person')) {
                        return 1;
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return 0;
    }

    /**
     * Override to filter Amazon review title from star rating text.
     */
    protected function extractTextFromSelectors(Crawler $node, array $selectors): ?string
    {
        foreach ($selectors as $selector) {
            try {
                $element = $node->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    // Filter out star rating text and "Read more" text
                    if (! empty($text) && ! str_contains($text, 'out of 5 stars') && $text !== 'Read more') {
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
     * Override to check for Amazon-specific review ID patterns.
     */
    protected function extractExternalIdFromDom(Crawler $node): ?string
    {
        $id = $node->attr('id')
            ?? $node->attr('data-review-id')
            ?? null;

        if ($id !== null) {
            return $id;
        }

        // Try to find review ID in child elements
        try {
            $idElement = $node->filter('[id^="customer_review-"], [id^="review-"]');
            if ($idElement->count() > 0) {
                return $idElement->first()->attr('id');
            }
        } catch (\Exception $e) {
            // Continue
        }

        // Generate a hash-based ID as fallback
        try {
            return md5($node->text());
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract next page URL for review pagination.
     */
    private function extractNextPageUrl(Crawler $crawler): ?string
    {
        try {
            // Look for "Next page" link in reviews section
            $nextLinks = $crawler->filter('.a-pagination .a-last a, [data-hook="pagination-bar"] .a-pagination .a-last a');
            if ($nextLinks->count() > 0) {
                $href = $nextLinks->first()->attr('href');
                if ($href !== null) {
                    // Make absolute URL if relative
                    if (str_starts_with($href, '/')) {
                        return 'https://www.amazon.co.uk'.$href;
                    }

                    return $href;
                }
            }
        } catch (\Exception $e) {
            // Continue
        }

        return null;
    }

    /**
     * Build a reviews page URL for a given ASIN.
     */
    public static function buildReviewsUrl(string $asin, int $page = 1): string
    {
        $params = [
            'ie' => 'UTF8',
            'pageNumber' => $page,
            'sortBy' => 'recent',
        ];

        return 'https://www.amazon.co.uk/product-reviews/'.$asin.'?'.http_build_query($params);
    }
}
