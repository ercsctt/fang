<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\BM;

use App\Crawler\Extractors\BaseProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;
use Symfony\Component\DomCrawler\Crawler;

class BMProductDetailsExtractor extends BaseProductDetailsExtractor
{
    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'bmstores.co.uk')) {
            return str_contains($url, '/product/') || str_contains($url, '/p/') || str_contains($url, '/pd/');
        }

        return false;
    }

    protected function getRetailerSlug(): string
    {
        return 'bm';
    }

    protected function getTitleSelectors(): array
    {
        return [
            'h1.product-title',
            'h1.product-name',
            'h1.page-title',
            '.product-name h1',
            'h1',
        ];
    }

    protected function getPriceSelectors(): array
    {
        return [
            '.product-price .price',
            '.price',
            '.current-price',
            '.product-info-price',
            '.product-price',
            '.price-wrapper .price',
            '.price-final_price',
            '[data-price-type="finalPrice"] .price',
        ];
    }

    protected function getOriginalPriceSelectors(): array
    {
        return [
            '.old-price .price',
            '.was-price',
            '.price-was',
            '.original-price',
            '.price-original',
        ];
    }

    protected function getDescriptionSelectors(): array
    {
        return [
            '.product-description',
            '#description',
            '.description',
            '.product-info-description',
            '.product-details',
            '.product-info',
        ];
    }

    protected function getImageSelectors(): array
    {
        return [
            '.product-image img',
            '.product-image-main img',
            '.product-media img',
            '.gallery-image img',
            '.product-images img',
            '#main-image',
        ];
    }

    protected function getBrandSelectors(): array
    {
        return [
            '.product-brand',
            '.brand',
            '[data-brand]',
            '.product-attribute-brand',
            '.product-details-brand',
            '.brand-name',
            '.product-info-brand',
        ];
    }

    protected function getWeightSelectors(): array
    {
        return [
            '.product-weight',
            '[data-weight]',
            '.weight',
            '.size',
        ];
    }

    protected function getIngredientsSelectors(): array
    {
        return [
            '.ingredients',
            '.product-ingredients',
            '#ingredients',
            '.composition',
            '.product-info-ingredients',
        ];
    }

    protected function getOutOfStockSelectors(): array
    {
        return [
            '.out-of-stock',
            '[data-stock-status="out"]',
            '.sold-out',
            '.unavailable',
            '[data-testid="out-of-stock"]',
        ];
    }

    protected function getInStockSelectors(): array
    {
        return [
            '.in-stock',
            '[data-stock-status="in"]',
            '[data-stock-status="available"]',
            '.available',
            '[data-testid="in-stock"]',
        ];
    }

    protected function getAddToCartSelectors(): array
    {
        return [];
    }

    protected function getQuantityPatterns(): array
    {
        return [
            '/(\d+)\s*(?:pack|x|pcs|pieces|count)/i',
        ];
    }

    protected function shouldCombineFirstTwoBrandWords(): bool
    {
        return false;
    }

    protected function getBrandSkipWords(): array
    {
        return [
            'home',
            'products',
            'pet',
            'pets',
            'dog',
            'cat',
            'food',
            'treats',
            'accessories',
            'shop',
            'all',
            'new',
            'sale',
            'the',
            'and',
            'or',
        ];
    }

    protected function looksLikeBrand(string $text): bool
    {
        $skipWords = $this->getBrandSkipWords();

        return ! empty($text)
            && strlen($text) > 2
            && ! in_array(strtolower($text), $skipWords, true)
            && preg_match('/^[A-Z]/', $text);
    }

    protected function extractBrandFromBreadcrumbs(Crawler $crawler): ?string
    {
        $breadcrumbSelectors = [
            '.breadcrumb a',
            '.breadcrumbs a',
            '[data-testid="breadcrumb"] a',
            'nav.breadcrumb a',
        ];

        foreach ($breadcrumbSelectors as $selector) {
            try {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 0) {
                    $breadcrumbs = $elements->each(fn (Crawler $node) => trim($node->text()));
                    $breadcrumbs = array_filter($breadcrumbs);

                    foreach ($breadcrumbs as $crumb) {
                        if ($this->looksLikeBrand($crumb)) {
                            return $crumb;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    protected function extractStockStatus(Crawler $crawler, array $jsonLdData): bool
    {
        foreach ($this->getOutOfStockSelectors() as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    return false;
                }
            } catch (\Exception $e) {
                // Continue checking
            }
        }

        foreach ($this->getInStockSelectors() as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    return true;
                }
            } catch (\Exception $e) {
                // Continue checking
            }
        }

        try {
            $productElement = $crawler->filter('[data-stock-status]');
            if ($productElement->count() > 0) {
                $status = strtolower($productElement->first()->attr('data-stock-status') ?? '');
                if (in_array($status, ['out', 'outofstock', 'out-of-stock', 'unavailable'], true)) {
                    return false;
                }
                if (in_array($status, ['in', 'instock', 'in-stock', 'available'], true)) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Default to in stock
        }

        return true;
    }

    public function extractExternalId(string $url, ?Crawler $crawler = null, array $jsonLdData = []): ?string
    {
        $crawler = $crawler ?? new Crawler('');

        if (preg_match('/\/product\/[^\/]*?-(\d+)(?:\/|$|\?)/i', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\/p\/(\d+)/i', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\/pd\/([a-z0-9-]+)/i', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('/(?:product[_-]?id)=([a-z0-9-]+)/i', $url, $matches)) {
            return $matches[1];
        }

        $idSelectors = [
            '[data-product-id]',
            '[data-sku]',
            '[data-item-id]',
            'input[name="product_id"]',
        ];

        foreach ($idSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $id = $element->first()->attr('data-product-id')
                        ?? $element->first()->attr('data-sku')
                        ?? $element->first()->attr('data-item-id')
                        ?? $element->first()->attr('value');

                    if ($id !== null && ! empty(trim($id))) {
                        return trim($id);
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    protected function extractCategory(Crawler $crawler, string $url): ?string
    {
        return $this->categoryExtractor->extractFromBreadcrumbs($crawler)
            ?? $this->categoryExtractor->extractFromUrl($url);
    }

    protected function buildMetadata(
        Crawler $crawler,
        string $url,
        ?string $externalId,
        array $jsonLdData,
        array $weightData
    ): array {
        return array_merge(parent::buildMetadata($crawler, $url, $externalId, $jsonLdData, $weightData), [
            'product_id' => $externalId,
        ]);
    }
}
