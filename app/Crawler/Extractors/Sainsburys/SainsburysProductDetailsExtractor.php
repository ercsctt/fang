<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Sainsburys;

use App\Crawler\Extractors\BaseProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;
use Symfony\Component\DomCrawler\Crawler;

class SainsburysProductDetailsExtractor extends BaseProductDetailsExtractor
{
    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    public function canHandle(string $url): bool
    {
        if (! str_contains($url, 'sainsburys.co.uk')) {
            return false;
        }

        return (bool) preg_match(
            '/\/gol-ui\/product\/|\/product\/[a-z0-9-]+-\d+|\/shop\/gb\/groceries\/[^\/]+\/[a-z0-9-]+--\d+/i',
            $url
        );
    }

    protected function getRetailerSlug(): string
    {
        return 'sainsburys';
    }

    protected function getTitleSelectors(): array
    {
        return [
            '[data-test-id="pd-product-title"]',
            '[data-testid="pd-product-title"]',
            '.pd__header h1',
            '.product-details__title',
            '.productNameTitle',
            'h1.pd__name',
            '[data-test-id="product-title"]',
            'h1',
        ];
    }

    protected function getPriceSelectors(): array
    {
        return [
            '[data-test-id="pd-product-price"]',
            '[data-testid="pd-product-price"]',
            '.pd__cost__price',
            '.pd__cost__price--promo',
            '.product-details__price',
            '.pricePerUnit',
            '[data-test-id="product-price"]',
            '.price',
        ];
    }

    protected function getOriginalPriceSelectors(): array
    {
        return [
            '[data-test-id="pd-was-price"]',
            '[data-testid="pd-was-price"]',
            '.pd__cost__was-price',
            '.was-price',
            '.price-was',
            '.original-price',
            's.price',
            'del.price',
            '.strikethrough-price',
            '.pricing__was',
        ];
    }

    protected function getDescriptionSelectors(): array
    {
        return [
            '[data-test-id="product-description"]',
            '[data-testid="product-description"]',
            '.pd__description',
            '.productDescription',
            '.product-info__description',
            '#product-description',
            '.pd__content__description',
        ];
    }

    protected function getImageSelectors(): array
    {
        return [
            '[data-test-id="pd-image"] img',
            '[data-testid="pd-image"] img',
            '.pd__image img',
            '.productImage img',
            '.product-image-wrapper img',
            '.slick-slide img',
            '.pd__gallery img',
        ];
    }

    protected function getBrandSelectors(): array
    {
        return [
            '[data-test-id="pd-brand"]',
            '[data-testid="pd-brand"]',
            '.pd__brand',
            '.product-brand',
            '.brand-name',
            '[data-brand]',
            'a[href*="/brands/"]',
        ];
    }

    protected function getWeightSelectors(): array
    {
        return [
            '[data-test-id="pd-weight"]',
            '[data-testid="pd-weight"]',
            '.pd__weight',
            '.product-weight',
            '.product-size',
            '[data-weight]',
        ];
    }

    protected function getIngredientsSelectors(): array
    {
        return [
            '[data-test-id="ingredients"]',
            '[data-testid="ingredients"]',
            '.ingredients',
            '.product-ingredients',
            '#ingredients',
            '.composition',
            '.ingredient-list',
        ];
    }

    protected function getOutOfStockSelectors(): array
    {
        return [
            '[data-test-id="pd-out-of-stock"]',
            '[data-testid="pd-out-of-stock"]',
            '.out-of-stock',
            '.sold-out',
            '.unavailable',
            '.product--unavailable',
        ];
    }

    protected function getInStockSelectors(): array
    {
        return [
            '.in-stock',
            '.available',
        ];
    }

    protected function getAddToCartSelectors(): array
    {
        return [
            '[data-test-id="pd-add-to-basket"]',
            '[data-testid="pd-add-to-basket"]',
            '.add-to-basket:not([disabled])',
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
            'sainsburys',
            "sainsbury's",
        ];
    }

    public function extractExternalId(string $url, ?Crawler $crawler = null, array $jsonLdData = []): ?string
    {
        if (preg_match('/--(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\/product\/[a-z0-9-]+-(\d+)/i', $url, $matches)) {
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

    protected function buildMetadata(
        Crawler $crawler,
        string $url,
        ?string $externalId,
        array $jsonLdData,
        array $weightData
    ): array {
        return array_merge(parent::buildMetadata($crawler, $url, $externalId, $jsonLdData, $weightData), [
            'nectar_price_pence' => $this->extractNectarPrice($crawler),
            'multi_buy_offer' => $this->extractMultiBuyOffer($crawler),
            'rating_value' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
            'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
        ]);
    }

    protected function normalizeImageUrl(string $url): string
    {
        if (preg_match('/\?.*w=\d+/', $url)) {
            return preg_replace('/(\?.*w=)\d+/', '$1800', $url) ?? $url;
        }

        if (! str_contains($url, '?')) {
            return $url.'?w=800';
        }

        return $url;
    }

    private function extractNectarPrice(Crawler $crawler): ?int
    {
        $element = $this->selectFirst(
            $crawler,
            [
                '[data-test-id="pd-nectar-price"]',
                '[data-testid="pd-nectar-price"]',
                '.nectar-price',
                '.loyalty-price',
                '.offer-price--nectar',
                '.price--nectar',
            ],
            'Nectar price',
            fn (Crawler $node) => ($this->parsePriceToPence($node->text()) ?? 0) > 0
        );

        return $element !== null ? $this->parsePriceToPence($element->text()) : null;
    }

    private function extractMultiBuyOffer(Crawler $crawler): ?array
    {
        $element = $this->selectFirst(
            $crawler,
            [
                '[data-test-id="pd-multibuy"]',
                '[data-testid="pd-multibuy"]',
                '.multibuy',
                '.offer-multibuy',
                '.price__promotion',
                '.promotion',
            ],
            'Multi-buy offer',
            fn (Crawler $node) => trim($node->text()) !== ''
        );

        if ($element === null) {
            return null;
        }

        $text = trim($element->text());

        return $text !== '' ? $this->parseMultiBuyOffer($text) : null;
    }

    private function parseMultiBuyOffer(string $text): array
    {
        $result = [
            'text' => $text,
            'quantity' => null,
            'price' => null,
        ];

        if (preg_match('/(\d+)\s+for\s+£?(\d+(?:\.\d{2})?)/', $text, $matches)) {
            $result['quantity'] = (int) $matches[1];
            $result['price'] = (int) round((float) $matches[2] * 100);
        }

        if (preg_match('/buy\s+(\d+)\s+get\s+(\d+)\s+free/i', $text, $matches)) {
            $result['quantity'] = (int) $matches[1] + (int) $matches[2];
        }

        return $result;
    }
}
