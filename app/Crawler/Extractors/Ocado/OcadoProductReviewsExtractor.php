<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Ocado;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductReview;
use App\Crawler\Extractors\Concerns\SelectsElements;
use Carbon\Carbon;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class OcadoProductReviewsExtractor implements ExtractorInterface
{
    use SelectsElements;

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Check for blocked page
        if ($this->isBlockedPage($crawler, $html)) {
            Log::warning("OcadoProductReviewsExtractor: Blocked/CAPTCHA page detected at {$url}");

            return;
        }

        $reviewCount = 0;

        // Try to extract from JSON-LD first (most reliable)
        $jsonLdReviews = $this->extractJsonLdReviews($crawler);
        foreach ($jsonLdReviews as $review) {
            $reviewCount++;
            yield $review;
        }

        // If no JSON-LD reviews, try DOM selectors
        if ($reviewCount === 0) {
            $reviewSelectors = [
                '[data-testid="review-item"]',
                '.review-item',
                '.customer-review',
                '.product-review',
                '.fop-review',
                '[data-review-id]',
            ];

            $reviews = $this->selectAll($crawler, $reviewSelectors, 'Reviews');
            if ($reviews !== null) {
                foreach ($reviews as $reviewNode) {
                    $review = $this->parseReview(new Crawler($reviewNode), $url, $reviewCount);
                    if ($review !== null) {
                        $reviewCount++;
                        yield $review;
                    }
                }
            }
        }

        Log::info("OcadoProductReviewsExtractor: Extracted {$reviewCount} reviews from {$url}");
    }

    public function canHandle(string $url): bool
    {
        if (! str_contains($url, 'ocado.com')) {
            return false;
        }

        // Handle product pages (reviews embedded)
        if (preg_match('/\/products\/[a-z0-9-]+-\d+$/i', $url)) {
            return true;
        }

        // Handle dedicated review pages if Ocado has them
        if (str_contains($url, '/reviews/') || str_contains($url, '/customer-reviews/')) {
            return true;
        }

        return false;
    }

    /**
     * Check if the page is blocked or shows a CAPTCHA.
     */
    private function isBlockedPage(Crawler $crawler, string $html): bool
    {
        if (str_contains(strtolower($html), 'captcha') || str_contains(strtolower($html), 'robot check')) {
            return true;
        }

        $titleNode = $this->selectFirst(
            $crawler,
            ['title'],
            'Blocked page title',
            fn (Crawler $node) => trim($node->text()) !== ''
        );

        if ($titleNode !== null) {
            $titleText = strtolower($titleNode->text());
            if (str_contains($titleText, 'access denied') || str_contains($titleText, 'blocked')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract reviews from JSON-LD structured data.
     *
     * @return Generator<ProductReview>
     */
    private function extractJsonLdReviews(Crawler $crawler): Generator
    {
        $scripts = $this->selectAll(
            $crawler,
            ['script[type="application/ld+json"]'],
            'JSON-LD reviews'
        );

        if ($scripts === null) {
            return;
        }

        foreach ($scripts as $script) {
            $content = $script->textContent;
            $data = json_decode($content, true);

            if ($data === null) {
                continue;
            }

            // Handle @graph format
            if (isset($data['@graph'])) {
                foreach ($data['@graph'] as $item) {
                    if (($item['@type'] ?? null) === 'Product') {
                        yield from $this->parseJsonLdProductReviews($item);
                    }
                }
            }

            // Direct Product type with reviews
            if (($data['@type'] ?? null) === 'Product' && isset($data['review'])) {
                yield from $this->parseJsonLdProductReviews($data);
            }
        }
    }

    /**
     * Parse reviews from a JSON-LD Product object.
     *
     * @param  array<string, mixed>  $product
     * @return Generator<ProductReview>
     */
    private function parseJsonLdProductReviews(array $product): Generator
    {
        if (! isset($product['review'])) {
            return;
        }

        $reviews = $product['review'];

        // Handle single review (not in array)
        if (isset($reviews['@type'])) {
            $reviews = [$reviews];
        }

        $index = 0;
        foreach ($reviews as $reviewData) {
            $review = $this->parseJsonLdReview($reviewData, $index);
            if ($review !== null) {
                yield $review;
                $index++;
            }
        }
    }

    /**
     * Parse a single review from JSON-LD data.
     *
     * @param  array<string, mixed>  $reviewData
     */
    private function parseJsonLdReview(array $reviewData, int $index): ?ProductReview
    {
        try {
            // Extract rating
            $rating = null;
            if (isset($reviewData['reviewRating']['ratingValue'])) {
                $rating = (float) $reviewData['reviewRating']['ratingValue'];
            } elseif (isset($reviewData['ratingValue'])) {
                $rating = (float) $reviewData['ratingValue'];
            }

            if ($rating === null) {
                return null;
            }

            // Extract body
            $body = $reviewData['reviewBody'] ?? $reviewData['description'] ?? null;
            if (empty($body)) {
                return null;
            }

            // Extract author
            $author = null;
            if (isset($reviewData['author'])) {
                $author = is_string($reviewData['author'])
                    ? $reviewData['author']
                    : ($reviewData['author']['name'] ?? null);
            }

            // Extract date
            $reviewDate = null;
            if (isset($reviewData['datePublished'])) {
                try {
                    $reviewDate = Carbon::parse($reviewData['datePublished']);
                } catch (\Exception $e) {
                    // Continue without date
                }
            }

            // Generate external ID
            $externalId = $reviewData['@id']
                ?? $reviewData['identifier']
                ?? 'ocado-review-'.md5($body.$author).'-'.$index;

            return new ProductReview(
                externalId: $externalId,
                rating: $rating,
                author: $author,
                title: $reviewData['name'] ?? $reviewData['headline'] ?? null,
                body: $body,
                verifiedPurchase: $reviewData['verifiedPurchase'] ?? false,
                reviewDate: $reviewDate,
                helpfulCount: 0,
                metadata: [
                    'source' => 'json-ld',
                    'retailer' => 'ocado',
                    'extracted_at' => now()->toIso8601String(),
                ],
            );
        } catch (\Exception $e) {
            Log::debug("OcadoProductReviewsExtractor: Failed to parse JSON-LD review: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Parse a single review element into a ProductReview DTO.
     */
    private function parseReview(Crawler $reviewNode, string $sourceUrl, int $index): ?ProductReview
    {
        try {
            // Extract review ID
            $reviewId = $reviewNode->attr('data-review-id')
                ?? $reviewNode->attr('data-testid')
                ?? $reviewNode->attr('id')
                ?? null;

            // Extract rating (out of 5)
            $rating = $this->extractRating($reviewNode);
            if ($rating === null) {
                Log::debug('OcadoProductReviewsExtractor: Could not extract rating for review');

                return null;
            }

            // Extract review body
            $body = $this->extractReviewBody($reviewNode);
            if (empty($body)) {
                Log::debug('OcadoProductReviewsExtractor: Could not extract body for review');

                return null;
            }

            // Generate ID if not found
            if ($reviewId === null) {
                $reviewId = 'ocado-review-'.md5($sourceUrl.$body).'-'.$index;
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
                    'retailer' => 'ocado',
                ],
            );
        } catch (\Exception $e) {
            Log::debug("OcadoProductReviewsExtractor: Failed to parse review: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Extract rating from review element.
     */
    private function extractRating(Crawler $reviewNode): ?float
    {
        $selectors = [
            '[data-testid="review-rating"]',
            '.review-rating',
            '.star-rating',
            '.rating',
            '.fop-rating',
        ];

        $ratingElement = $this->selectFirst(
            $reviewNode,
            $selectors,
            'Rating',
            fn (Crawler $node) => $this->extractRatingValueFromElement($node) !== null
        );

        if ($ratingElement !== null) {
            $value = $this->extractRatingValueFromElement($ratingElement);
            if ($value !== null) {
                return $value;
            }
        }

        $filledStars = $this->selectAll(
            $reviewNode,
            ['.star-filled, .star-full, [data-star="filled"]'],
            'Rating'
        );

        if ($filledStars !== null) {
            return (float) $filledStars->count();
        }

        return null;
    }

    /**
     * Extract review body text.
     */
    private function extractReviewBody(Crawler $reviewNode): ?string
    {
        $selectors = [
            '[data-testid="review-body"]',
            '.review-body',
            '.review-text',
            '.review-content',
            '.review-description',
            '.fop-review-body',
            'p',
        ];

        $element = $this->selectFirst(
            $reviewNode,
            $selectors,
            'Review body',
            fn (Crawler $node) => strlen(trim($node->text())) > 10
        );

        return $element !== null ? trim($element->text()) : null;
    }

    /**
     * Extract review title.
     */
    private function extractReviewTitle(Crawler $reviewNode): ?string
    {
        $selectors = [
            '[data-testid="review-title"]',
            '.review-title',
            '.review-headline',
            '.fop-review-title',
            'h3',
            'h4',
        ];

        $element = $this->selectFirst(
            $reviewNode,
            $selectors,
            'Review title',
            fn (Crawler $node) => trim($node->text()) !== ''
        );

        return $element !== null ? trim($element->text()) : null;
    }

    /**
     * Extract author name.
     */
    private function extractAuthor(Crawler $reviewNode): ?string
    {
        $selectors = [
            '[data-testid="review-author"]',
            '.review-author',
            '.reviewer-name',
            '.author-name',
            '.fop-review-author',
        ];

        $element = $this->selectFirst(
            $reviewNode,
            $selectors,
            'Review author',
            fn (Crawler $node) => trim($node->text()) !== ''
        );

        return $element !== null ? trim($element->text()) : null;
    }

    /**
     * Extract review date.
     */
    private function extractReviewDate(Crawler $reviewNode): ?\DateTimeInterface
    {
        $selectors = [
            '[data-testid="review-date"]',
            '.review-date',
            '.fop-review-date',
            'time',
        ];

        $element = $this->selectFirst(
            $reviewNode,
            $selectors,
            'Review date',
            fn (Crawler $node) => $this->parseReviewDateFromNode($node) !== null
        );

        return $element !== null ? $this->parseReviewDateFromNode($element) : null;
    }

    /**
     * Check if review is from a verified purchase.
     */
    private function isVerifiedPurchase(Crawler $reviewNode): bool
    {
        $selectors = [
            '[data-testid="verified-purchase"]',
            '.verified-purchase',
            '.verified-badge',
            '.fop-verified',
        ];

        if ($this->selectFirst($reviewNode, $selectors, 'Verified purchase') !== null) {
            return true;
        }

        // Check text content for verified indicators
        try {
            $text = strtolower($reviewNode->text());
            if (str_contains($text, 'verified purchase') || str_contains($text, 'verified buyer')) {
                return true;
            }
        } catch (\Exception $e) {
            // Continue
        }

        return false;
    }

    /**
     * Extract helpful vote count.
     */
    private function extractHelpfulCount(Crawler $reviewNode): int
    {
        $selectors = [
            '[data-testid="helpful-count"]',
            '.helpful-count',
            '.vote-count',
            '.fop-helpful-count',
        ];

        $element = $this->selectFirst(
            $reviewNode,
            $selectors,
            'Helpful count',
            fn (Crawler $node) => $this->extractHelpfulCountFromNode($node) !== null
        );

        return $element !== null ? $this->extractHelpfulCountFromNode($element) : 0;
    }

    private function extractRatingValueFromElement(Crawler $ratingNode): ?float
    {
        $dataRating = $ratingNode->attr('data-rating');
        if ($dataRating !== null && is_numeric($dataRating)) {
            return (float) $dataRating;
        }

        $ariaLabel = $ratingNode->attr('aria-label');
        if ($ariaLabel !== null && preg_match('/([\d.]+)\s*(?:out of\s*5|stars?)/i', $ariaLabel, $matches)) {
            return (float) $matches[1];
        }

        return $this->extractRatingFromText($ratingNode->text());
    }

    private function extractRatingFromText(string $text): ?float
    {
        if (preg_match('/([\d.]+)\s*(?:out of\s*5|stars?|\/\s*5)/i', $text, $matches)) {
            return (float) $matches[1];
        }

        if (preg_match('/^([\d.]+)$/', trim($text), $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    private function parseReviewDateFromNode(Crawler $dateNode): ?\DateTimeInterface
    {
        $datetime = $dateNode->attr('datetime');
        if ($datetime !== null) {
            try {
                return Carbon::parse($datetime);
            } catch (\Exception $e) {
                return null;
            }
        }

        $text = trim($dateNode->text());
        if ($text === '') {
            return null;
        }

        if (preg_match('/(\d{1,2}(?:st|nd|rd|th)?\s+\w+\s+\d{4})/i', $text, $matches)) {
            try {
                return Carbon::parse($matches[1]);
            } catch (\Exception $e) {
                return null;
            }
        }

        try {
            return Carbon::parse($text);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractHelpfulCountFromNode(Crawler $helpfulNode): ?int
    {
        $text = strtolower($helpfulNode->text());

        if (preg_match('/(\d+)\s*(?:people|person)/i', $text, $matches)) {
            return (int) $matches[1];
        }

        if (str_contains($text, 'one person')) {
            return 1;
        }

        if (preg_match('/^(\d+)$/', trim($helpfulNode->text()), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
