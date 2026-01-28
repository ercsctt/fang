<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\JustForPets;

use App\Crawler\Extractors\Concerns\BaseProductReviewsExtractor;
use Symfony\Component\DomCrawler\Crawler;

class JFPProductReviewsExtractor extends BaseProductReviewsExtractor
{
    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'justforpetsonline.co.uk')) {
            // Handle product URLs with various patterns
            return (bool) preg_match('#/(product|products)/[a-z0-9-]+#i', $url)
                || preg_match('#/p/\d+#', $url)
                || preg_match('#-p-\d+\.html#i', $url)
                || preg_match('#/[a-z0-9-]+-\d+\.html$#i', $url)
                || preg_match('#/[a-z0-9-]+/[a-z0-9-]+\.html$#i', $url);
        }

        return false;
    }

    protected function getExtractorName(): string
    {
        return 'JFPProductReviewsExtractor';
    }

    protected function getRetailerSlug(): string
    {
        return 'jfp';
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
            '.woocommerce-Reviews .review',
            '.comment-review',
            '#reviews .review',
        ];
    }

    protected function getReviewBodySelectors(): array
    {
        return [
            '.review-body',
            '.review-text',
            '.review-content',
            '[itemprop="reviewBody"]',
            '.description',
            '.comment-text',
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
            '.woocommerce-review__author',
            '.comment-author',
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
            '.star-rating',
        ];
    }

    protected function getDateSelectors(): array
    {
        return [
            '[itemprop="datePublished"]',
            '.review-date',
            '.date',
            'time',
            '.woocommerce-review__published-date',
            '.comment-date',
        ];
    }

    protected function getVerifiedPurchaseSelectors(): array
    {
        return [
            '.verified-purchase',
            '.verified-buyer',
            '[data-verified="true"]',
            '.badge-verified',
            '.verified',
            '.woocommerce-review__verified',
        ];
    }

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

    protected function getFilledStarSelector(): string
    {
        return '.star-filled, .star-full, .fa-star:not(.fa-star-o), .icon-star-filled, .star.active';
    }

    /**
     * Override to include WooCommerce-specific percentage width style.
     */
    protected function extractRatingFromDom(Crawler $node): ?float
    {
        // Try the base implementation first
        $rating = parent::extractRatingFromDom($node);
        if ($rating !== null) {
            return $rating;
        }

        // Try WooCommerce style rating (percentage width)
        try {
            $ratingNode = $node->filter('.star-rating');
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

        return null;
    }
}
