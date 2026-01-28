<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\JustForPets;

use App\Crawler\Extractors\BaseProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;
use Symfony\Component\DomCrawler\Crawler;

class JFPProductDetailsExtractor extends BaseProductDetailsExtractor
{
    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    public function canHandle(string $url): bool
    {
        if (! preg_match('/justforpetsonline\.co\.uk|justforpets\.co\.uk/i', $url)) {
            return false;
        }

        return (bool) preg_match(
            '#/(?:products?|product|p)/|/product-[^/]+\.html|/product-p-\d+\.html#i',
            $url
        );
    }

    protected function getRetailerSlug(): string
    {
        return 'just-for-pets';
    }

    protected function getBrandConfigKey(): string
    {
        return 'justforpets';
    }

    protected function getTitleSelectors(): array
    {
        return [
            'h1[data-testid="product-title"]',
            'h1.product-title',
            '.product-name h1',
            '.product-detail h1',
            '.product-info h1',
            '[data-product-title]',
            '[itemprop="name"]',
            'h1',
        ];
    }

    protected function getPriceSelectors(): array
    {
        return [
            '[data-testid="product-price"]',
            '.product-price',
            '.price-current',
            '.current-price',
            '[data-price]',
            '[itemprop="price"]',
            '.price .amount',
            '.price',
            '.product-price-current',
            '.woocommerce-Price-amount',
        ];
    }

    protected function getOriginalPriceSelectors(): array
    {
        return [
            '.was-price',
            '.price-was',
            '.original-price',
            '.price-rrp',
            '[data-original-price]',
            's.price',
            'del.price',
            '.strikethrough-price',
            '.old-price',
            '.regular-price del',
            '.woocommerce-Price-amount del',
        ];
    }

    protected function getDescriptionSelectors(): array
    {
        return [
            '[data-testid="product-description"]',
            '.product-description',
            '.description-content',
            '#product-description',
            '.product-info-description',
            '[itemprop="description"]',
            '.product-details-description',
            '.woocommerce-product-details__short-description',
            '.product-summary',
        ];
    }

    protected function getImageSelectors(): array
    {
        return [
            '.product-image img',
            '.gallery img',
            '[data-product-image]',
            '.product-gallery img',
            '.carousel img',
            '.product-media img',
            '.woocommerce-product-gallery img',
            '[itemprop="image"]',
            '.product-thumbnails img',
            '.product-main-image img',
        ];
    }

    protected function getBrandSelectors(): array
    {
        return [
            '[data-testid="product-brand"]',
            '.product-brand',
            '.brand-name',
            '[data-brand]',
            'a[href*="/brands/"]',
            'a[href*="/brand/"]',
            '[itemprop="brand"]',
            '.manufacturer',
        ];
    }

    protected function getWeightSelectors(): array
    {
        return [
            '.product-weight',
            '[data-weight]',
            '.size-selector option:checked',
            '.weight-selector .selected',
            '.product-size',
            '.variation-size',
            '[itemprop="weight"]',
        ];
    }

    protected function getIngredientsSelectors(): array
    {
        return [
            '.ingredients',
            '[data-ingredients]',
            '.product-ingredients',
            '#ingredients',
            '.composition',
            '[data-testid="ingredients"]',
            '.ingredient-list',
            '.product-composition',
            '#tab-description .ingredients',
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
            '.stock.out-of-stock',
        ];
    }

    protected function getInStockSelectors(): array
    {
        return [
            '.in-stock',
            '[data-stock-status="in"]',
            '.available',
            '[data-testid="in-stock"]',
            '.stock.in-stock',
        ];
    }

    protected function getAddToCartSelectors(): array
    {
        return [];
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
            'dry',
            'wet',
            'adult',
            'puppy',
            'senior',
            'kitten',
            'complete',
            'premium',
            'natural',
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

    protected function extractWeightAndQuantity(string $title, Crawler $crawler, array $jsonLdData = []): array
    {
        $weight = null;
        $quantity = null;

        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];
            if (isset($offers['name'])) {
                $weight = $this->parseWeight($offers['name']);
            } elseif (is_array($offers)) {
                foreach ($offers as $offer) {
                    if (! empty($offer['name'])) {
                        $weight = $this->parseWeight($offer['name']);
                        if ($weight !== null) {
                            break;
                        }
                    }
                }
            }
        }

        if ($weight === null) {
            $weight = $this->extractWeightFromSelectors($crawler);
        }

        if ($weight === null && $title !== '') {
            $weight = $this->parseWeight($title);
        }

        $quantity = $this->extractQuantityFromTitle($title);

        return [
            'weight' => $weight,
            'quantity' => $quantity,
        ];
    }

    public function extractExternalId(string $url, ?Crawler $crawler = null, array $jsonLdData = []): ?string
    {
        $crawler = $crawler ?? new Crawler('');

        if (! empty($jsonLdData['sku'])) {
            return (string) $jsonLdData['sku'];
        }

        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];
            if (isset($offers['sku'])) {
                return (string) $offers['sku'];
            }
            if (is_array($offers) && ! empty($offers[0]['sku'])) {
                return (string) $offers[0]['sku'];
            }
        }

        if (! empty($jsonLdData['productID'])) {
            return (string) $jsonLdData['productID'];
        }

        if (preg_match('#/products?/[a-z0-9-]+-(\d+)(?:\.html)?$#i', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('#/p/(\d+)#', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('#-p-(\d+)\.html#i', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('#-(\d+)\.html$#i', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('#/([^/]+)\.html$#i', $url, $matches)) {
            return $matches[1];
        }

        $idSelectors = [
            '[data-product-id]',
            '[data-id]',
            '[data-item-id]',
            'input[name="product_id"]',
        ];

        foreach ($idSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $id = $element->first()->attr('data-product-id')
                        ?? $element->first()->attr('data-id')
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

    protected function extractIngredients(Crawler $crawler): ?string
    {
        $ingredients = parent::extractIngredients($crawler);
        if ($ingredients !== null) {
            return $ingredients;
        }

        try {
            $descriptionElement = $crawler->filter('.product-description, .description, #tab-description');
            if ($descriptionElement->count() > 0) {
                $html = $descriptionElement->first()->html();

                if ($html !== null && preg_match('/(?:ingredients|composition)[:\s]*([^<]+(?:<[^>]+>[^<]+)*)/i', $html, $matches)) {
                    $fallback = strip_tags($matches[1]);
                    $fallback = trim($fallback);
                    if (! empty($fallback)) {
                        return $fallback;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logDebug("Ingredients fallback extraction failed: {$e->getMessage()}");
        }

        return null;
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
}
