<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\BM;

use App\Crawler\Extractors\BaseProductListingUrlExtractor;

class BMProductListingUrlExtractor extends BaseProductListingUrlExtractor
{
    public function canHandle(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host === 'bmstores.co.uk' || $host === 'www.bmstores.co.uk';
    }

    protected function getProductLinkSelectors(): array
    {
        return [
            'a[href]',
        ];
    }

    protected function isProductUrl(string $url): bool
    {
        // B&M product URLs typically contain /product/ or have numeric product IDs
        return preg_match('#/product/#', $url)
            || preg_match('#/p/\d+#', $url)
            || preg_match('#/pd/[a-z0-9-]+#i', $url);
    }

    protected function getRetailerSlug(): string
    {
        return 'bm';
    }

    /**
     * BM extracts category from the product URL instead of source URL.
     */
    protected function extractCategoryForProduct(string $productUrl, string $sourceUrl): ?string
    {
        return $this->extractCategoryFromBmUrl($productUrl);
    }

    /**
     * Extract category from BM URL path.
     */
    private function extractCategoryFromBmUrl(string $url): ?string
    {
        // Try to extract category from URL path
        if (preg_match('/\/(pet|dog|cat|puppy)[^\/]*\//i', $url, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
