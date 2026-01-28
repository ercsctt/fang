<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Morrisons;

use App\Crawler\Extractors\Concerns\BaseProductReviewsExtractor;

class MorrisonsProductReviewsExtractor extends BaseProductReviewsExtractor
{
    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'morrisons.com')) {
            return (bool) preg_match('/\/products\/[\w-]+\/\w+/', $url);
        }

        return false;
    }

    protected function getExtractorName(): string
    {
        return 'MorrisonsProductReviewsExtractor';
    }

    protected function getRetailerSlug(): string
    {
        return 'morrisons';
    }

    protected function getReviewSelectors(): array
    {
        return [
            '.review-item',
            '.reviews__item',
            '.customer-review',
            '[data-test="review"]',
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
            '[data-test="review-text"]',
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
            '[data-test="review-author"]',
        ];
    }

    protected function getReviewTitleSelectors(): array
    {
        return [
            '.review-title',
            '.review-headline',
            '[itemprop="name"]',
            '[data-test="review-title"]',
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
            '[data-test="rating"]',
        ];
    }

    protected function getDateSelectors(): array
    {
        return [
            '[itemprop="datePublished"]',
            '.review-date',
            '.date',
            'time',
            '[data-test="review-date"]',
        ];
    }

    protected function getVerifiedPurchaseSelectors(): array
    {
        return [
            '.verified-purchase',
            '.verified-buyer',
            '[data-verified="true"]',
            '.badge-verified',
            '[data-test="verified-purchase"]',
        ];
    }

    protected function getHelpfulCountSelectors(): array
    {
        return [
            '.helpful-count',
            '.vote-count',
            '[data-helpful-count]',
            '.upvotes',
            '[data-test="helpful-count"]',
        ];
    }

    protected function getFilledStarSelector(): string
    {
        return '.star-filled, .star-full, .fa-star:not(.fa-star-o), .icon-star-filled';
    }
}
