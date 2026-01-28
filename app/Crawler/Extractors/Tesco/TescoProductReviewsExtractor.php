<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Tesco;

use App\Crawler\Extractors\Concerns\BaseProductReviewsExtractor;
use Symfony\Component\DomCrawler\Crawler;

class TescoProductReviewsExtractor extends BaseProductReviewsExtractor
{
    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'tesco.com')) {
            return (bool) preg_match('/\/groceries\/en-GB\/products\/\d+/', $url);
        }

        return false;
    }

    protected function getExtractorName(): string
    {
        return 'TescoProductReviewsExtractor';
    }

    protected function getRetailerSlug(): string
    {
        return 'tesco';
    }

    protected function getReviewSelectors(): array
    {
        return [
            '.review-item',
            '.reviews__item',
            '.customer-review',
            '[data-auto="review"]',
            '.reviews-list .review',
            '.product-review',
            '[itemtype*="Review"]',
        ];
    }

    protected function getReviewBodySelectors(): array
    {
        return [
            '.review-body',
            '.review-text',
            '.review-content',
            '[itemprop="reviewBody"]',
            '[data-auto="review-text"]',
            'p',
        ];
    }

    protected function getReviewAuthorSelectors(): array
    {
        return [
            '.review-author',
            '.author-name',
            '[itemprop="author"]',
            '.reviewer-name',
            '[data-auto="review-author"]',
        ];
    }

    protected function getReviewTitleSelectors(): array
    {
        return [
            '.review-title',
            '.review-headline',
            '[itemprop="name"]',
            '[data-auto="review-title"]',
            'h3',
            'h4',
        ];
    }

    protected function getRatingSelectors(): array
    {
        return [
            '[data-rating]',
            '[data-score]',
            '[data-stars]',
            '.review-rating',
            '.rating',
            '[data-auto="rating"]',
        ];
    }

    protected function getDateSelectors(): array
    {
        return [
            '[itemprop="datePublished"]',
            '.review-date',
            '.date',
            'time',
            '[data-auto="review-date"]',
        ];
    }

    protected function getVerifiedPurchaseSelectors(): array
    {
        return [
            '.verified-purchase',
            '.verified-buyer',
            '[data-verified="true"]',
            '.badge-verified',
            '[data-auto="verified-purchase"]',
        ];
    }

    protected function getHelpfulCountSelectors(): array
    {
        return [
            '.helpful-count',
            '.vote-count',
            '[data-helpful-count]',
            '.upvotes',
            '[data-auto="helpful-count"]',
        ];
    }

    protected function getFilledStarSelector(): string
    {
        return '.star-filled, .star-full, .fa-star:not(.fa-star-o), .icon-star-filled, .beans-star--filled';
    }

    /**
     * Override to check additional Tesco-specific ID attributes.
     */
    protected function extractExternalIdFromDom(Crawler $node): ?string
    {
        return $node->attr('data-review-id')
            ?? $node->attr('id')
            ?? null;
    }
}
