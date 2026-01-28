<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\BM;

use App\Crawler\Extractors\Concerns\BaseProductReviewsExtractor;

class BMProductReviewsExtractor extends BaseProductReviewsExtractor
{
    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'bmstores.co.uk')) {
            return str_contains($url, '/product/')
                || preg_match('/\/p\/\d+/', $url) === 1
                || preg_match('/\/pd\/[a-z0-9-]+/i', $url) === 1;
        }

        return false;
    }

    protected function getExtractorName(): string
    {
        return 'BMProductReviewsExtractor';
    }

    protected function getRetailerSlug(): string
    {
        return 'bm';
    }

    protected function getReviewSelectors(): array
    {
        return [
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
            '.review-body',
            '.review-text',
            '.review-content',
            '[itemprop="reviewBody"]',
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
        ];
    }

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

    protected function getRatingSelectors(): array
    {
        return [
            '[data-rating]',
            '[data-score]',
            '[data-stars]',
            '.review-rating',
            '.rating',
        ];
    }

    protected function getDateSelectors(): array
    {
        return [
            '[itemprop="datePublished"]',
            '.review-date',
            '.date',
            'time',
        ];
    }

    protected function getVerifiedPurchaseSelectors(): array
    {
        return [
            '.verified-purchase',
            '.verified-buyer',
            '[data-verified="true"]',
            '.badge-verified',
        ];
    }

    protected function getHelpfulCountSelectors(): array
    {
        return [
            '.helpful-count',
            '.vote-count',
            '[data-helpful-count]',
            '.upvotes',
        ];
    }

    protected function getFilledStarSelector(): string
    {
        return '.star-filled, .star-full, .fa-star:not(.fa-star-o), .icon-star-filled';
    }
}
