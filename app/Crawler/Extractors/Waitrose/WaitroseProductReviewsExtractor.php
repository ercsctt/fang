<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Waitrose;

use App\Crawler\Extractors\Concerns\BaseProductReviewsExtractor;
use Symfony\Component\DomCrawler\Crawler;

class WaitroseProductReviewsExtractor extends BaseProductReviewsExtractor
{
    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'waitrose.com')) {
            // Handle product URLs: /ecom/products/{product-slug}/{product-id}
            return (bool) preg_match('/\/ecom\/products\/[a-z0-9-]+\/[a-z0-9-]+/i', $url);
        }

        return false;
    }

    protected function getExtractorName(): string
    {
        return 'WaitroseProductReviewsExtractor';
    }

    protected function getRetailerSlug(): string
    {
        return 'waitrose';
    }

    protected function getReviewSelectors(): array
    {
        return [
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
    }

    protected function getReviewBodySelectors(): array
    {
        return [
            '[data-test="review-body"]',
            '[data-testid="review-body"]',
            '.review-body',
            '.review-text',
            '.review-content',
            '.bv-content-summary-body-text',
            '[itemprop="reviewBody"]',
            '.description',
            'p',
        ];
    }

    protected function getReviewAuthorSelectors(): array
    {
        return [
            '[data-test="review-author"]',
            '[data-testid="review-author"]',
            '.review-author',
            '.author-name',
            '.bv-author',
            '[itemprop="author"]',
            '.reviewer-name',
        ];
    }

    protected function getReviewTitleSelectors(): array
    {
        return [
            '[data-test="review-title"]',
            '[data-testid="review-title"]',
            '.review-title',
            '.review-headline',
            '.bv-content-title',
            '[itemprop="name"]',
            'h3',
            'h4',
        ];
    }

    protected function getRatingSelectors(): array
    {
        return [
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
    }

    protected function getDateSelectors(): array
    {
        return [
            '[data-test="review-date"]',
            '[data-testid="review-date"]',
            '[itemprop="datePublished"]',
            '.review-date',
            '.date',
            'time',
            '.bv-content-datetime',
            '.bv-content-datetime-stamp',
        ];
    }

    protected function getVerifiedPurchaseSelectors(): array
    {
        return [
            '[data-test="verified-purchase"]',
            '[data-testid="verified-purchase"]',
            '.verified-purchase',
            '.verified-buyer',
            '[data-verified="true"]',
            '.badge-verified',
            '.verified',
            '.bv-content-badges-verified',
        ];
    }

    protected function getHelpfulCountSelectors(): array
    {
        return [
            '[data-test="helpful-count"]',
            '[data-testid="helpful-count"]',
            '.helpful-count',
            '.vote-count',
            '[data-helpful-count]',
            '.upvotes',
            '.helpful-votes',
            '.bv-content-feedback-vote-positive',
        ];
    }

    protected function getFilledStarSelector(): string
    {
        return '.star-filled, .star-full, .fa-star:not(.fa-star-o), .icon-star-filled, .star.active, .bv-glyph-star-full';
    }

    /**
     * Override to check Bazaarvoice-specific ID attributes.
     */
    protected function extractExternalIdFromDom(Crawler $node): ?string
    {
        return $node->attr('data-review-id')
            ?? $node->attr('data-bv-content-id')
            ?? $node->attr('id')
            ?? null;
    }

    /**
     * Build a URL for fetching reviews for a product.
     */
    public static function buildReviewsUrl(string $productSlug, string $productId, int $page = 1): string
    {
        return "https://www.waitrose.com/ecom/products/{$productSlug}/{$productId}/reviews?page={$page}";
    }
}
