<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Zooplus;

use App\Crawler\Extractors\BaseProductDetailsExtractor;
use App\Crawler\Extractors\Concerns\ExtractsBarcode;
use App\Crawler\Services\CategoryExtractor;
use Symfony\Component\DomCrawler\Crawler;

class ZooplusProductDetailsExtractor extends BaseProductDetailsExtractor
{
    use ExtractsBarcode;

    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    public function canHandle(string $url): bool
    {
        if (! str_contains($url, 'zooplus.co.uk')) {
            return false;
        }

        return (bool) preg_match('/\/shop\/dogs\/[a-z0-9_\/]+\/[a-z0-9-]+_(\d{4,})/i', $url);
    }

    protected function getRetailerSlug(): string
    {
        return 'zooplus-uk';
    }

    protected function getBrandConfigKey(): string
    {
        return 'zooplus';
    }

    protected function getTitleSelectors(): array
    {
        return [
            '[data-zta="productTitle"]',
            '[data-testid="product-title"]',
            '.product-details__title',
            '.ProductTitle',
            'h1[itemprop="name"]',
            '.product__name',
            '.productName',
            'h1',
        ];
    }

    protected function getPriceSelectors(): array
    {
        return [
            '[data-zta="productPriceAmount"]',
            '[data-testid="product-price"]',
            '.product-price__amount',
            '.ProductPrice__amount',
            '.price-module__price',
            '[itemprop="price"]',
            '.product__price',
            '.productPrice',
            '[data-price]',
            '.price',
        ];
    }

    protected function getOriginalPriceSelectors(): array
    {
        return [
            '[data-zta="productPriceWas"]',
            '[data-testid="was-price"]',
            '.product-price__was',
            '.ProductPrice__was',
            '.price-module__was-price',
            '.was-price',
            '.price-was',
            '.original-price',
            's.price',
            'del.price',
            '.strikethrough-price',
        ];
    }

    protected function getDescriptionSelectors(): array
    {
        return [
            '[data-zta="productDescription"]',
            '[data-testid="product-description"]',
            '.product-description',
            '.ProductDescription',
            '[itemprop="description"]',
            '.product__description',
            '#product-description',
            '.productDescription',
            '.product-info__description',
        ];
    }

    protected function getImageSelectors(): array
    {
        return [
            '[data-zta="productImage"] img',
            '[data-testid="product-image"] img',
            '.product-image__main img',
            '.ProductImage img',
            '.product-gallery__image img',
            '.product-image img',
            '.product__images img',
            '[itemprop="image"]',
        ];
    }

    protected function getBrandSelectors(): array
    {
        return [
            '[data-zta="productBrand"]',
            '[data-testid="product-brand"]',
            '.product-brand',
            '.ProductBrand',
            '[itemprop="brand"]',
            '.product__brand',
            '.brand-name',
            '[data-brand]',
            'a[href*="/brand/"]',
        ];
    }

    protected function getWeightSelectors(): array
    {
        return [
            '[data-zta="productSize"]',
            '[data-testid="product-weight"]',
            '.product-size',
            '.ProductSize',
            '.product-weight',
            '.product__weight',
            '[data-weight]',
            '.variant-selector__option--selected',
        ];
    }

    protected function getIngredientsSelectors(): array
    {
        return [
            '[data-zta="ingredients"]',
            '[data-testid="ingredients"]',
            '.product-composition',
            '.ProductComposition',
            '.ingredients',
            '.product-ingredients',
            '#ingredients',
            '.composition',
            '[data-tab="composition"]',
        ];
    }

    protected function getOutOfStockSelectors(): array
    {
        return [
            '[data-zta="outOfStock"]',
            '[data-testid="out-of-stock"]',
            '.out-of-stock',
            '.sold-out',
            '.unavailable',
            '.product--unavailable',
            '.product-availability--unavailable',
        ];
    }

    protected function getInStockSelectors(): array
    {
        return [
            '[data-testid="in-stock"]',
            '.in-stock',
            '.available',
        ];
    }

    protected function getAddToCartSelectors(): array
    {
        return [
            '[data-zta="addToCart"]',
            '.add-to-basket:not([disabled])',
            'button[data-zta="addToCartButton"]:not([disabled])',
        ];
    }

    protected function getRetailerSpecificBrandSkipWords(): array
    {
        return [
            'home',
            'products',
            'pets',
            'accessories',
            'shop',
            'all',
            'sale',
            'and',
            'or',
            'zooplus',
        ];
    }

    public function extractExternalId(string $url, ?Crawler $crawler = null, array $jsonLdData = []): ?string
    {
        if (preg_match('/_(\d{4,})(?:\?|$)/', $url, $matches)) {
            return $matches[1];
        }

        if (! empty($jsonLdData['sku'])) {
            return (string) $jsonLdData['sku'];
        }

        if (! empty($jsonLdData['productID'])) {
            return (string) $jsonLdData['productID'];
        }

        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];
            if (isset($offers['@type']) || isset($offers['price'])) {
                $offers = [$offers];
            }

            foreach ($offers as $offer) {
                if (! empty($offer['sku'])) {
                    return (string) $offer['sku'];
                }
            }
        }

        if ($crawler !== null) {
            $idSelectors = [
                '[data-product-id]',
                '[data-sku]',
                '[data-product-code]',
                'input[name="product_id"]',
            ];

            foreach ($idSelectors as $selector) {
                try {
                    $element = $crawler->filter($selector);
                    if ($element->count() > 0) {
                        $id = $element->first()->attr('data-product-id')
                            ?? $element->first()->attr('data-sku')
                            ?? $element->first()->attr('data-product-code')
                            ?? $element->first()->attr('value');

                        if ($id !== null && trim($id) !== '') {
                            return trim($id);
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return null;
    }

    protected function extractCategory(Crawler $crawler, string $url): ?string
    {
        return $this->categoryExtractor->extractFromBreadcrumbs($crawler)
            ?? $this->categoryExtractor->extractFromUrl($url);
    }

    protected function extractNutritionalInfo(Crawler $crawler): ?array
    {
        $nutritionalInfo = [];

        $selectors = [
            '[data-zta="analyticalConstituents"]',
            '[data-testid="nutritional-info"]',
            '.analytical-constituents',
            '.product-analytical-constituents',
            '.ProductNutritionalValues',
            '.nutritional-info',
            '.analysis',
            '[data-tab="analysis"]',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $rows = $element->filter('tr');
                    if ($rows->count() > 0) {
                        $rows->each(function (Crawler $row) use (&$nutritionalInfo) {
                            $cells = $row->filter('td, th');
                            if ($cells->count() >= 2) {
                                $key = trim($cells->eq(0)->text());
                                $value = trim($cells->eq(1)->text());

                                if ($key !== '' && $value !== '') {
                                    $nutritionalInfo[$key] = $value;
                                }
                            }
                        });

                        continue;
                    }

                    $text = trim($element->first()->text());
                    if ($text === '') {
                        continue;
                    }

                    $lines = preg_split('/\r?\n/', $text);
                    if ($lines === false) {
                        continue;
                    }

                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '') {
                            continue;
                        }

                        if (preg_match('/^([\w\s]+)\s*[:\-]\s*([\d.,]+%?)/', $line, $matches)) {
                            $key = trim($matches[1]);
                            $value = trim($matches[2]);
                            $nutritionalInfo[$key] = $value;
                        }
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return ! empty($nutritionalInfo) ? $nutritionalInfo : null;
    }

    protected function buildMetadata(
        Crawler $crawler,
        string $url,
        ?string $externalId,
        array $jsonLdData,
        array $weightData
    ): array {
        return array_merge(parent::buildMetadata($crawler, $url, $externalId, $jsonLdData, $weightData), [
            'rating_value' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
            'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
        ]);
    }

    protected function extractImages(Crawler $crawler, array $jsonLdData): array
    {
        $images = parent::extractImages($crawler, $jsonLdData);

        $elements = $this->selectAll($crawler, $this->getImageSelectors(), 'Images');
        if ($elements === null) {
            return $images;
        }

        $elements->each(function (Crawler $node) use (&$images) {
            $src = $node->attr('src')
                ?? $node->attr('data-src')
                ?? $node->attr('data-lazy-src')
                ?? $node->attr('content');

            if ($src === null) {
                return;
            }

            $normalized = $this->normalizeImageUrl($src);
            if ($this->shouldIncludeImageUrl($normalized, $images)) {
                $images[] = $normalized;
            }
        });

        return array_values(array_unique($images));
    }
}
