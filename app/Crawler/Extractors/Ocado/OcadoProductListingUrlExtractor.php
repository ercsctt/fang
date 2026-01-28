<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Ocado;

use App\Crawler\Extractors\BaseProductListingUrlExtractor;
use Symfony\Component\DomCrawler\Crawler;

class OcadoProductListingUrlExtractor extends BaseProductListingUrlExtractor
{
    public function canHandle(string $url): bool
    {
        // Handle category/browse pages, not product pages
        if (! str_contains($url, 'ocado.com')) {
            return false;
        }

        // Category pages use /browse/ or /search/ patterns
        return str_contains($url, '/browse/') || str_contains($url, '/search/');
    }

    protected function getProductLinkSelectors(): array
    {
        return [
            'a[href*="/products/"]',
        ];
    }

    protected function isProductUrl(string $url): bool
    {
        // Ocado product URLs: /products/{product-name-slug}-{sku}
        // SKU is typically a numeric ID at the end
        // Example: /products/royal-canin-mini-adult-dog-food-2kg-567890
        return (bool) preg_match('/\/products\/[a-z0-9-]+-\d+$/i', $url);
    }

    protected function getRetailerSlug(): string
    {
        return 'ocado';
    }

    protected function supportsPagination(): bool
    {
        return true;
    }

    /**
     * Check if the page is blocked or shows a CAPTCHA.
     */
    protected function shouldExtract(Crawler $crawler, string $html, string $url): bool
    {
        if ($this->isBlockedPage($crawler, $html)) {
            $this->logWarning("Blocked/CAPTCHA page detected at {$url}");

            return false;
        }

        return true;
    }

    /**
     * Check if the page is blocked or shows a CAPTCHA.
     */
    private function isBlockedPage(Crawler $crawler, string $html): bool
    {
        if (str_contains(strtolower($html), 'captcha') || str_contains(strtolower($html), 'robot check')) {
            return true;
        }

        try {
            $title = $crawler->filter('title');
            if ($title->count() > 0) {
                $titleText = strtolower($title->text());
                if (str_contains($titleText, 'access denied') || str_contains($titleText, 'blocked')) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Continue
        }

        return false;
    }

    /**
     * Override to extract page number from Ocado URL patterns.
     */
    protected function extractCurrentPageNumber(string $url): int
    {
        // Ocado uses ?page=N pattern
        if (preg_match('/[?&]page=(\d+)/i', $url, $matches)) {
            return (int) $matches[1];
        }

        return 1;
    }
}
