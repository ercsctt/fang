<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductDetails;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\DTOs\ProductReview;

/**
 * Provides helper methods for testing extractors.
 *
 * Usage in Pest tests:
 * - Add to pest()->extend() in Pest.php or use uses() in individual test files
 * - Call helper methods via $this-> in test closures
 */
trait ExtractorTestHelpers
{
    /**
     * Load a fixture file from the tests/Fixtures directory.
     */
    protected function loadFixture(string $filename): string
    {
        $path = base_path("tests/Fixtures/{$filename}");

        if (! file_exists($path)) {
            throw new \RuntimeException("Fixture file not found: {$filename}");
        }

        return file_get_contents($path);
    }

    /**
     * Extract results from an extractor and convert to array.
     *
     * @param  \Generator|iterable  $generator
     * @return array<int, mixed>
     */
    protected function extractToArray(iterable $generator): array
    {
        return iterator_to_array($generator);
    }

    /**
     * Filter results to only ProductListingUrl DTOs.
     *
     * @param  array<int, mixed>  $results
     * @return array<int, ProductListingUrl>
     */
    protected function filterProductListingUrls(array $results): array
    {
        return array_values(
            array_filter($results, fn ($item) => $item instanceof ProductListingUrl)
        );
    }

    /**
     * Filter results to only ProductDetails DTOs.
     *
     * @param  array<int, mixed>  $results
     * @return array<int, ProductDetails>
     */
    protected function filterProductDetails(array $results): array
    {
        return array_values(
            array_filter($results, fn ($item) => $item instanceof ProductDetails)
        );
    }

    /**
     * Filter results to only ProductReview DTOs.
     *
     * @param  array<int, mixed>  $results
     * @return array<int, ProductReview>
     */
    protected function filterProductReviews(array $results): array
    {
        return array_values(
            array_filter($results, fn ($item) => $item instanceof ProductReview)
        );
    }

    /**
     * Filter results to only PaginatedUrl DTOs.
     *
     * @param  array<int, mixed>  $results
     * @return array<int, PaginatedUrl>
     */
    protected function filterPaginatedUrls(array $results): array
    {
        return array_values(
            array_filter($results, fn ($item) => $item instanceof PaginatedUrl)
        );
    }

    /**
     * Assert that a ProductListingUrl DTO has the expected structure.
     */
    protected function assertProductListingUrlDto(
        ProductListingUrl $dto,
        string $expectedRetailer,
        ?string $expectedUrlPattern = null
    ): void {
        expect($dto)->toBeInstanceOf(ProductListingUrl::class)
            ->and($dto->retailer)->toBe($expectedRetailer)
            ->and($dto->url)->toBeString()
            ->and($dto->metadata)->toBeArray()
            ->and($dto->metadata)->toHaveKey('discovered_from')
            ->and($dto->metadata)->toHaveKey('discovered_at');

        if ($expectedUrlPattern !== null) {
            expect($dto->url)->toContain($expectedUrlPattern);
        }
    }

    /**
     * Assert that a ProductDetails DTO has the expected structure.
     */
    protected function assertProductDetailsDto(
        ProductDetails $dto,
        string $expectedRetailer
    ): void {
        expect($dto)->toBeInstanceOf(ProductDetails::class)
            ->and($dto->title)->toBeString()
            ->and($dto->metadata)->toBeArray()
            ->and($dto->metadata)->toHaveKey('retailer')
            ->and($dto->metadata['retailer'])->toBe($expectedRetailer)
            ->and($dto->metadata)->toHaveKey('source_url')
            ->and($dto->metadata)->toHaveKey('extracted_at');
    }

    /**
     * Assert that a ProductReview DTO has the expected structure.
     */
    protected function assertProductReviewDto(
        ProductReview $dto,
        string $expectedSource = 'json-ld'
    ): void {
        expect($dto)->toBeInstanceOf(ProductReview::class)
            ->and($dto->rating)->toBeFloat()
            ->and($dto->body)->toBeString()
            ->and($dto->externalId)->toBeString()
            ->and($dto->metadata)->toBeArray()
            ->and($dto->metadata)->toHaveKey('source')
            ->and($dto->metadata['source'])->toBe($expectedSource)
            ->and($dto->metadata)->toHaveKey('source_url')
            ->and($dto->metadata)->toHaveKey('extracted_at');
    }

    /**
     * Assert that an extractor can handle a URL.
     *
     * @param  object  $extractor  The extractor instance
     * @param  string  $url  The URL to test
     */
    protected function assertCanHandleUrl(object $extractor, string $url): void
    {
        expect($extractor->canHandle($url))->toBeTrue();
    }

    /**
     * Assert that an extractor cannot handle a URL.
     *
     * @param  object  $extractor  The extractor instance
     * @param  string  $url  The URL to test
     */
    protected function assertCannotHandleUrl(object $extractor, string $url): void
    {
        expect($extractor->canHandle($url))->toBeFalse();
    }

    /**
     * Generate minimal HTML for testing.
     */
    protected function minimalHtml(string $bodyContent = ''): string
    {
        return "<html><body>{$bodyContent}</body></html>";
    }

    /**
     * Generate empty HTML for edge case testing.
     */
    protected function emptyHtml(): string
    {
        return '<html><body></body></html>';
    }

    /**
     * Generate HTML with a single anchor tag.
     */
    protected function htmlWithLink(string $href, string $text = 'Product'): string
    {
        return $this->minimalHtml("<a href=\"{$href}\">{$text}</a>");
    }

    /**
     * Generate HTML with multiple anchor tags.
     *
     * @param  array<int, array{href: string, text?: string}>  $links
     */
    protected function htmlWithLinks(array $links): string
    {
        $linksHtml = '';
        foreach ($links as $link) {
            $text = $link['text'] ?? 'Product';
            $linksHtml .= "<a href=\"{$link['href']}\">{$text}</a>";
        }

        return $this->minimalHtml($linksHtml);
    }

    /**
     * Generate HTML with JSON-LD structured data.
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    protected function htmlWithJsonLd(array $jsonLdData, string $additionalBody = ''): string
    {
        $jsonLd = json_encode($jsonLdData, JSON_PRETTY_PRINT);

        return <<<HTML
        <html>
        <head>
            <script type="application/ld+json">
            {$jsonLd}
            </script>
        </head>
        <body>{$additionalBody}</body>
        </html>
        HTML;
    }

    /**
     * Generate a basic Product JSON-LD structure.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function productJsonLd(array $overrides = []): array
    {
        return array_merge([
            '@type' => 'Product',
            'name' => 'Test Product',
            'offers' => [
                '@type' => 'Offer',
                'price' => '10.00',
                'priceCurrency' => 'GBP',
            ],
        ], $overrides);
    }

    /**
     * Generate a Product JSON-LD with reviews.
     *
     * @param  array<int, array<string, mixed>>  $reviews
     * @return array<string, mixed>
     */
    protected function productJsonLdWithReviews(array $reviews): array
    {
        return $this->productJsonLd(['review' => $reviews]);
    }

    /**
     * Generate a basic review structure for JSON-LD.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function reviewJsonLd(array $overrides = []): array
    {
        return array_merge([
            '@type' => 'Review',
            'reviewRating' => [
                'ratingValue' => '5',
            ],
            'author' => 'Test User',
            'reviewBody' => 'Test review body',
        ], $overrides);
    }
}
