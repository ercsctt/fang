<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Amazon;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductReview;
use Carbon\Carbon;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AmazonProductReviewsExtractor implements ExtractorInterface
{
    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Check for blocked page
        if ($this->isBlockedPage($crawler, $html)) {
            Log::warning("AmazonProductReviewsExtractor: Blocked/CAPTCHA page detected at {$url}");

            return;
        }

        $reviewCount = 0;

        // Extract reviews from product page review section
        $reviewSelectors = [
            '#cm-cr-dp-review-list .review',
            '#customer_review_foreign .review',
            '[data-hook="review"]',
            '.review',
        ];

        foreach ($reviewSelectors as $selector) {
            try {
                $reviews = $crawler->filter($selector);
                if ($reviews->count() > 0) {
                    foreach ($reviews as $reviewNode) {
                        $review = $this->parseReview(new Crawler($reviewNode), $url);
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
                Log::debug("AmazonProductReviewsExtractor: Review selector {$selector} failed: {$e->getMessage()}");
            }
        }

        // Extract pagination info for metadata
        $nextPageUrl = $this->extractNextPageUrl($crawler, $url);

        Log::info("AmazonProductReviewsExtractor: Extracted {$reviewCount} reviews from {$url}".
            ($nextPageUrl ? ' (more pages available)' : ''));
    }

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

    /**
     * Check if the page is blocked or shows a CAPTCHA.
     */
    private function isBlockedPage(Crawler $crawler, string $html): bool
    {
        if (str_contains($html, 'captcha') || str_contains($html, 'robot check')) {
            return true;
        }

        try {
            $sorryTitle = $crawler->filter('title');
            if ($sorryTitle->count() > 0) {
                $title = strtolower($sorryTitle->text());
                if (str_contains($title, 'sorry') || str_contains($title, 'robot')) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Continue
        }

        return false;
    }

    /**
     * Parse a single review element into a ProductReview DTO.
     */
    private function parseReview(Crawler $reviewNode, string $sourceUrl): ?ProductReview
    {
        try {
            // Extract review ID
            $reviewId = $reviewNode->attr('id')
                ?? $reviewNode->attr('data-review-id')
                ?? null;

            if ($reviewId === null) {
                // Try to find review ID in child elements
                $idElement = $reviewNode->filter('[id^="customer_review-"], [id^="review-"]');
                if ($idElement->count() > 0) {
                    $reviewId = $idElement->first()->attr('id');
                }
            }

            if ($reviewId === null) {
                // Generate a hash-based ID as fallback
                $reviewId = md5($reviewNode->text());
            }

            // Extract rating (out of 5)
            $rating = $this->extractRating($reviewNode);
            if ($rating === null) {
                Log::debug("AmazonProductReviewsExtractor: Could not extract rating for review {$reviewId}");

                return null;
            }

            // Extract review body
            $body = $this->extractReviewBody($reviewNode);
            if (empty($body)) {
                Log::debug("AmazonProductReviewsExtractor: Could not extract body for review {$reviewId}");

                return null;
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
                    'retailer' => 'amazon-uk',
                ],
            );
        } catch (\Exception $e) {
            Log::debug("AmazonProductReviewsExtractor: Failed to parse review: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Extract rating from review element.
     */
    private function extractRating(Crawler $reviewNode): ?float
    {
        $selectors = [
            '[data-hook="review-star-rating"] .a-icon-alt',
            '.review-rating .a-icon-alt',
            '.a-icon-star .a-icon-alt',
            'i[data-hook="review-star-rating"]',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
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
            $starIcon = $reviewNode->filter('[class*="a-star-"]');
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
     * Extract review body text.
     */
    private function extractReviewBody(Crawler $reviewNode): ?string
    {
        $selectors = [
            '[data-hook="review-body"] span',
            '.review-text-content span',
            '.review-text span',
            '.review-body',
            '.reviewText',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    if (! empty($text) && $text !== 'Read more') {
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
            '[data-hook="review-title"] span:not(.a-icon-alt)',
            '.review-title-content span',
            '.review-title span',
            '.a-text-bold',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    // Filter out star rating text that might be captured
                    if (! empty($text) && ! str_contains($text, 'out of 5 stars')) {
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
            '.a-profile-name',
            '[data-hook="review-author"] .a-profile-name',
            '.author',
            '.reviewer-name',
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
            '[data-hook="review-date"]',
            '.review-date',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
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
     * Check if review is from a verified purchase.
     */
    private function isVerifiedPurchase(Crawler $reviewNode): bool
    {
        $selectors = [
            '[data-hook="avp-badge"]',
            '.avp-badge',
            '.a-color-state',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
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
     * Extract helpful vote count.
     */
    private function extractHelpfulCount(Crawler $reviewNode): int
    {
        $selectors = [
            '[data-hook="helpful-vote-statement"]',
            '.cr-vote-text',
            '.a-color-tertiary:contains("helpful")',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $reviewNode->filter($selector);
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
     * Extract next page URL for review pagination.
     */
    private function extractNextPageUrl(Crawler $crawler, string $currentUrl): ?string
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
