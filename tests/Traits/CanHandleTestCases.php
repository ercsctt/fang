<?php

declare(strict_types=1);

namespace Tests\Traits;

/**
 * Provides structured canHandle test patterns for extractor tests.
 *
 * Usage in Pest tests:
 *
 * uses(CanHandleTestCases::class);
 *
 * beforeEach(fn () => $this->extractor = new MyExtractor);
 *
 * describe('canHandle', function () {
 *     $this->canHandleDataset(
 *         validUrls: [
 *             'https://example.com/category',
 *             'https://example.com/products',
 *         ],
 *         invalidUrls: [
 *             'https://other-domain.com/category',
 *             'https://example.com/about',
 *         ]
 *     );
 * });
 */
trait CanHandleTestCases
{
    /**
     * Test canHandle with multiple valid and invalid URLs.
     *
     * @param  array<int, string>  $validUrls  URLs that should return true
     * @param  array<int, string>  $invalidUrls  URLs that should return false
     */
    protected function testCanHandleUrls(array $validUrls, array $invalidUrls): void
    {
        foreach ($validUrls as $url) {
            expect($this->extractor->canHandle($url))
                ->toBeTrue("Expected canHandle to return true for: {$url}");
        }

        foreach ($invalidUrls as $url) {
            expect($this->extractor->canHandle($url))
                ->toBeFalse("Expected canHandle to return false for: {$url}");
        }
    }

    /**
     * Assert that the extractor handles the given URLs correctly.
     * This is an alias for testCanHandleUrls for more fluent usage.
     *
     * @param  array<int, string>  $validUrls
     * @param  array<int, string>  $invalidUrls
     */
    protected function assertHandlesUrls(array $validUrls, array $invalidUrls): void
    {
        $this->testCanHandleUrls($validUrls, $invalidUrls);
    }

    /**
     * Get common invalid URLs that no extractor should handle.
     * Useful for testing domain-specific extractors.
     *
     * @return array<string, string>
     */
    protected function commonInvalidUrls(): array
    {
        return [
            'empty' => '',
            'invalid_url' => 'not-a-url',
            'about_page' => 'https://www.example.com/about',
            'contact_page' => 'https://www.example.com/contact',
        ];
    }

    /**
     * Get common competitor domain URLs for UK retailers.
     * Useful for ensuring extractors don't match competitor domains.
     *
     * @param  array<int, string>  $exclude  Domains to exclude from the list
     * @return array<string, string>
     */
    protected function competitorDomainUrls(array $exclude = []): array
    {
        $domains = [
            'amazon' => 'https://www.amazon.co.uk/s?k=dog+food',
            'tesco' => 'https://www.tesco.com/groceries/en-GB/shop/pets/',
            'sainsburys' => 'https://www.sainsburys.co.uk/shop/gb/groceries/pets',
            'morrisons' => 'https://groceries.morrisons.com/browse/pet/dog',
            'asda' => 'https://groceries.asda.com/aisle/pet/dog-food',
            'ocado' => 'https://www.ocado.com/browse/pet-shop',
            'waitrose' => 'https://www.waitrose.com/ecom/shop/browse/pet',
            'petsathome' => 'https://www.petsathome.com/shop/en/pets/dog',
            'zooplus' => 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food',
            'bm' => 'https://www.bmstores.co.uk/products/pet',
            'justforpets' => 'https://www.justforpetsonline.co.uk/dog/dog-food/',
        ];

        return array_diff_key($domains, array_flip($exclude));
    }

    /**
     * Build a dataset array for use with Pest's ->with() method.
     *
     * @param  array<int|string, string>  $urls
     * @return array<string, array{0: string, 1: bool}>
     */
    protected function buildCanHandleDataset(array $urls, bool $expected): array
    {
        $dataset = [];
        foreach ($urls as $label => $url) {
            $key = is_string($label) ? $label : $url;
            $dataset[$key] = [$url, $expected];
        }

        return $dataset;
    }
}
