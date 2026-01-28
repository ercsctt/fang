<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Zooplus;

use App\Crawler\Extractors\Concerns\BaseProductReviewsExtractor;
use Symfony\Component\DomCrawler\Crawler;

class ZooplusProductReviewsExtractor extends BaseProductReviewsExtractor
{
    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'zooplus.co.uk')) {
            // Handle product URLs: /shop/dogs/.../product-name_123456
            return (bool) preg_match('/\/shop\/dogs\/[a-z0-9_\/]+\/[a-z0-9-]+_(\d{4,})/i', $url);
        }

        return false;
    }

    protected function getExtractorName(): string
    {
        return 'ZooplusProductReviewsExtractor';
    }

    protected function getRetailerSlug(): string
    {
        return 'zooplus';
    }

    protected function getReviewSelectors(): array
    {
        return [
            '[data-zta="reviewItem"]',
            '[data-testid="review-item"]',
            '.review-item',
            '.ReviewItem',
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
            '[data-zta="reviewBody"]',
            '[data-testid="review-body"]',
            '.review-body',
            '.review-text',
            '.review-content',
            '.ReviewBody',
            '[itemprop="reviewBody"]',
            '.description',
            'p',
        ];
    }

    protected function getReviewAuthorSelectors(): array
    {
        return [
            '[data-zta="reviewAuthor"]',
            '[data-testid="review-author"]',
            '.review-author',
            '.author-name',
            '.ReviewAuthor',
            '[itemprop="author"]',
            '.reviewer-name',
        ];
    }

    protected function getReviewTitleSelectors(): array
    {
        return [
            '[data-zta="reviewTitle"]',
            '[data-testid="review-title"]',
            '.review-title',
            '.review-headline',
            '.ReviewTitle',
            '[itemprop="name"]',
            'h3',
            'h4',
        ];
    }

    protected function getRatingSelectors(): array
    {
        return [
            '[data-zta="reviewRating"]',
            '[data-testid="review-rating"]',
            '.review-rating',
            '.ReviewRating',
            '[data-rating]',
            '[data-score]',
            '[data-stars]',
            '.rating',
            '.star-rating',
        ];
    }

    protected function getDateSelectors(): array
    {
        return [
            '[data-zta="reviewDate"]',
            '[data-testid="review-date"]',
            '[itemprop="datePublished"]',
            '.review-date',
            '.date',
            'time',
            '.ReviewDate',
        ];
    }

    protected function getVerifiedPurchaseSelectors(): array
    {
        return [
            '[data-zta="verifiedPurchase"]',
            '[data-testid="verified-purchase"]',
            '.verified-purchase',
            '.verified-buyer',
            '[data-verified="true"]',
            '.badge-verified',
            '.verified',
            '.VerifiedPurchase',
        ];
    }

    protected function getHelpfulCountSelectors(): array
    {
        return [
            '[data-zta="helpfulCount"]',
            '[data-testid="helpful-count"]',
            '.helpful-count',
            '.vote-count',
            '[data-helpful-count]',
            '.upvotes',
            '.helpful-votes',
            '.HelpfulCount',
        ];
    }

    protected function getFilledStarSelector(): string
    {
        return '.star-filled, .star-full, .fa-star:not(.fa-star-o), .icon-star-filled, .star.active';
    }

    /**
     * Override to check Zooplus-specific ID attributes.
     */
    protected function extractExternalIdFromDom(Crawler $node): ?string
    {
        return $node->attr('data-review-id')
            ?? $node->attr('data-id')
            ?? $node->attr('id')
            ?? null;
    }

    /**
     * Build a URL for fetching reviews for a product.
     */
    public static function buildReviewsUrl(string $productId, int $page = 1): string
    {
        return "https://www.zooplus.co.uk/reviews/{$productId}?page={$page}";
    }
}
