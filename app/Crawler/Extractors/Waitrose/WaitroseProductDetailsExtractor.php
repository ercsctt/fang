<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Waitrose;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Concerns\ExtractsBarcode;
use App\Crawler\Extractors\Concerns\ExtractsJsonLd;
use App\Crawler\Services\CategoryExtractor;
use App\Services\ProductNormalizer;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class WaitroseProductDetailsExtractor implements ExtractorInterface
{
    use ExtractsBarcode;
    use ExtractsJsonLd;

    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    /**
     * Weight conversion factors to grams.
     */

    /**
     * Get all known brands for Waitrose (core brands + Waitrose-specific brands).
     *
     * @return array<string>
     */
    private function getKnownBrands(): array
    {
        return array_merge(
            config('brands.known_brands', []),
            config('brands.retailer_specific.waitrose', [])
        );
    }

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Try to extract product data from JSON-LD first (most reliable)
        $jsonLdData = $this->extractJsonLd($crawler);

        $title = $this->extractTitle($crawler, $jsonLdData);
        $price = $this->extractPrice($crawler, $jsonLdData);

        if ($title === null) {
            Log::warning("WaitroseProductDetailsExtractor: Could not extract title from {$url}");
        }

        if ($price === null) {
            Log::warning("WaitroseProductDetailsExtractor: Could not extract price from {$url}");
        }

        $weightData = $this->extractWeightAndQuantity($title ?? '', $crawler, $jsonLdData);

        // Extract myWaitrose price separately
        $myWaitrosePrice = $this->extractMyWaitrosePrice($crawler);

        yield new ProductDetails(
            title: $title ?? 'Unknown Product',
            description: $this->extractDescription($crawler, $jsonLdData),
            brand: $this->extractBrand($crawler, $jsonLdData, $title),
            pricePence: $price ?? 0,
            originalPricePence: $this->extractOriginalPrice($crawler, $jsonLdData),
            currency: 'GBP',
            weightGrams: $weightData['weight'],
            quantity: $weightData['quantity'],
            images: $this->extractImages($crawler, $jsonLdData),
            ingredients: $this->extractIngredients($crawler),
            nutritionalInfo: null,
            inStock: $this->extractStockStatus($crawler, $jsonLdData),
            stockQuantity: null,
            externalId: $this->extractExternalId($url, $crawler, $jsonLdData),
            category: $this->extractCategory($crawler, $url),
            metadata: [
                'source_url' => $url,
                'extracted_at' => now()->toIso8601String(),
                'retailer' => 'waitrose',
                'mywaitrose_price_pence' => $myWaitrosePrice,
                'rating_value' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
                'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
            ],
            barcode: $this->extractBarcode($crawler, $jsonLdData),
        );

        Log::info("WaitroseProductDetailsExtractor: Successfully extracted product details from {$url}");
    }

    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'waitrose.com')) {
            // Handle product URLs: /ecom/products/{product-slug}/{product-id}
            return (bool) preg_match('/\/ecom\/products\/[a-z0-9-]+\/[a-z0-9-]+/i', $url);
        }

        return false;
    }

    /**
     * Extract product title.
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    private function extractTitle(Crawler $crawler, array $jsonLdData): ?string
    {
        // Try JSON-LD first
        if (! empty($jsonLdData['name'])) {
            return trim($jsonLdData['name']);
        }

        $selectors = [
            '[data-test="product-name"]',
            '[data-testid="product-name"]',
            'h1[data-test="product-title"]',
            '.product-hero__name',
            '.product__name',
            '.productName',
            '[data-productname]',
            'h1.product-title',
            '.pdp-title',
            'h1',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $title = trim($element->first()->text());
                    if (! empty($title)) {
                        return $title;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("WaitroseProductDetailsExtractor: Title selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract current price in pence.
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    private function extractPrice(Crawler $crawler, array $jsonLdData): ?int
    {
        // Try JSON-LD offers first
        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];

            // Check if offers is a single offer object (associative array) or array of offers
            if (isset($offers['@type']) || isset($offers['price'])) {
                $offers = [$offers];
            }

            foreach ($offers as $offer) {
                if (! empty($offer['price'])) {
                    $price = (float) $offer['price'];

                    return (int) round($price * 100);
                }
            }
        }

        $selectors = [
            '[data-test="product-price"]',
            '[data-testid="product-price"]',
            '.product-hero__price',
            '.price-per-sellable-unit .value',
            '.product__price',
            '.productPrice',
            '[data-price]',
            '.price',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $dataPrice = $element->first()->attr('data-price');
                    if ($dataPrice !== null) {
                        $price = $this->parsePriceToPence($dataPrice);
                        if ($price !== null) {
                            return $price;
                        }
                    }

                    $priceText = trim($element->first()->text());
                    $price = $this->parsePriceToPence($priceText);
                    if ($price !== null) {
                        return $price;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("WaitroseProductDetailsExtractor: Price selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract myWaitrose promotional price.
     */
    private function extractMyWaitrosePrice(Crawler $crawler): ?int
    {
        $selectors = [
            '[data-test="mywaitrose-price"]',
            '[data-testid="mywaitrose-price"]',
            '.mywaitrose-price',
            '.member-price',
            '.loyalty-price',
            '.offer-price--mywaitrose',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $priceText = trim($element->first()->text());
                    $price = $this->parsePriceToPence($priceText);
                    if ($price !== null) {
                        return $price;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("WaitroseProductDetailsExtractor: myWaitrose price selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract original/was price in pence.
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    private function extractOriginalPrice(Crawler $crawler, array $jsonLdData): ?int
    {
        $selectors = [
            '[data-test="was-price"]',
            '[data-testid="was-price"]',
            '.price-per-sellable-unit .was-price',
            '.product__price--was',
            '.was-price',
            '.price-was',
            '.original-price',
            's.price',
            'del.price',
            '.strikethrough-price',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $priceText = trim($element->first()->text());
                    $price = $this->parsePriceToPence($priceText);
                    if ($price !== null) {
                        return $price;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("WaitroseProductDetailsExtractor: Original price selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract product description.
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    private function extractDescription(Crawler $crawler, array $jsonLdData): ?string
    {
        // Try JSON-LD first
        if (! empty($jsonLdData['description'])) {
            return trim($jsonLdData['description']);
        }

        $selectors = [
            '[data-test="product-description"]',
            '[data-testid="product-description"]',
            '.product-description__content',
            '.product-info-block__content',
            '.product-description',
            '.product__description',
            '#product-description',
            '.productDescription',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $description = trim($element->first()->text());
                    if (! empty($description)) {
                        return $description;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("WaitroseProductDetailsExtractor: Description selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract product images.
     *
     * @param  array<string, mixed>  $jsonLdData
     * @return array<string>
     */
    private function extractImages(Crawler $crawler, array $jsonLdData): array
    {
        $images = [];

        // Try JSON-LD first
        if (! empty($jsonLdData['image'])) {
            $jsonImages = $jsonLdData['image'];
            if (is_string($jsonImages)) {
                $images[] = $jsonImages;
            } elseif (is_array($jsonImages)) {
                foreach ($jsonImages as $img) {
                    if (is_string($img)) {
                        $images[] = $img;
                    } elseif (is_array($img) && isset($img['url'])) {
                        $images[] = $img['url'];
                    }
                }
            }
        }

        // If no JSON-LD images, try DOM selectors
        if (empty($images)) {
            $selectors = [
                '[data-test="product-image"] img',
                '[data-testid="product-image"] img',
                '.product-hero__image img',
                '.product-image-wrapper img',
                '.product-image img',
                '.product__images img',
                '.pdp-image img',
                '.product-gallery img',
            ];

            foreach ($selectors as $selector) {
                try {
                    $elements = $crawler->filter($selector);
                    if ($elements->count() > 0) {
                        $elements->each(function (Crawler $node) use (&$images) {
                            $src = $node->attr('src')
                                ?? $node->attr('data-src')
                                ?? $node->attr('data-lazy-src');

                            if ($src && ! in_array($src, $images)) {
                                if (! str_contains($src, 'placeholder') && ! str_contains($src, 'loading')) {
                                    $images[] = $src;
                                }
                            }
                        });
                    }
                } catch (\Exception $e) {
                    Log::debug("WaitroseProductDetailsExtractor: Image selector {$selector} failed: {$e->getMessage()}");
                }
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * Extract product brand.
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    private function extractBrand(Crawler $crawler, array $jsonLdData, ?string $title): ?string
    {
        // Try JSON-LD brand first
        if (! empty($jsonLdData['brand'])) {
            $brand = $jsonLdData['brand'];
            if (is_string($brand)) {
                return $brand;
            }
            if (is_array($brand) && ! empty($brand['name'])) {
                return $brand['name'];
            }
        }

        // Try DOM selectors
        $selectors = [
            '[data-test="product-brand"]',
            '[data-testid="product-brand"]',
            '.product-hero__brand',
            '.product__brand',
            '.product-brand',
            '.brand-name',
            '[data-brand]',
            'a[href*="/brands/"]',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $brand = trim($element->first()->text());
                    if (! empty($brand)) {
                        return $brand;
                    }

                    $dataBrand = $element->first()->attr('data-brand');
                    if ($dataBrand !== null && ! empty(trim($dataBrand))) {
                        return trim($dataBrand);
                    }
                }
            } catch (\Exception $e) {
                Log::debug("WaitroseProductDetailsExtractor: Brand selector {$selector} failed: {$e->getMessage()}");
            }
        }

        // Try to extract from title
        if ($title !== null) {
            return $this->extractBrandFromTitle($title);
        }

        return null;
    }

    /**
     * Extract brand from product title.
     */
    private function extractBrandFromTitle(string $title): ?string
    {
        foreach ($this->getKnownBrands() as $brand) {
            if (stripos($title, $brand) !== false) {
                return $brand;
            }
        }

        // Try first word if it looks like a brand
        $words = explode(' ', $title);
        if (count($words) > 1) {
            $firstWord = $words[0];
            if ($this->looksLikeBrand($firstWord)) {
                return $firstWord;
            }
        }

        return null;
    }

    /**
     * Check if a string looks like it could be a brand name.
     */
    private function looksLikeBrand(string $text): bool
    {
        $skipWords = [
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
            'waitrose',
            'essential',
        ];

        return ! empty($text)
            && strlen($text) > 2
            && ! in_array(strtolower($text), $skipWords)
            && preg_match('/^[A-Z]/', $text);
    }

    /**
     * Extract weight and quantity.
     *
     * @param  array<string, mixed>  $jsonLdData
     * @return array{weight: int|null, quantity: int|null}
     */
    private function extractWeightAndQuantity(string $title, Crawler $crawler, array $jsonLdData): array
    {
        $weight = null;
        $quantity = null;

        // Try to get weight from JSON-LD offers
        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];

            // Check if offers is a single offer object (associative array) or array of offers
            if (isset($offers['@type']) || isset($offers['price'])) {
                $offers = [$offers];
            }

            foreach ($offers as $offer) {
                if (! empty($offer['name'])) {
                    $weight = $this->parseWeight($offer['name']);
                    if ($weight !== null) {
                        break;
                    }
                }
            }
        }

        // Try DOM selectors for weight/size
        if ($weight === null) {
            $weightSelectors = [
                '[data-test="product-weight"]',
                '[data-testid="product-weight"]',
                '.product-hero__size',
                '.product-weight',
                '.product__weight',
                '[data-weight]',
                '.product-size',
            ];

            foreach ($weightSelectors as $selector) {
                try {
                    $element = $crawler->filter($selector);
                    if ($element->count() > 0) {
                        $weight = $this->parseWeight($element->first()->text());
                        if ($weight !== null) {
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug("WaitroseProductDetailsExtractor: Weight selector {$selector} failed: {$e->getMessage()}");
                }
            }
        }

        // Fall back to parsing from title
        if ($weight === null) {
            $weight = $this->parseWeight($title);
        }

        // Extract quantity/pack size
        if (preg_match('/(\d+)\s*(?:pack|x|pcs|pieces|count)\b/i', $title, $matches)) {
            $quantity = (int) $matches[1];
        }

        // Handle "12 x 400g" pattern
        if (preg_match('/(\d+)\s*x\s*\d+/i', $title, $matches)) {
            $quantity = (int) $matches[1];
        }

        return [
            'weight' => $weight,
            'quantity' => $quantity,
        ];
    }

    /**
     * Parse weight text and convert to grams.
     */
    public function parseWeight(string $text): ?int
    {
        return app(ProductNormalizer::class)->parseWeight($text);
    }

    /**
     * Extract stock status.
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    private function extractStockStatus(Crawler $crawler, array $jsonLdData): bool
    {
        // Check JSON-LD offers for availability
        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];

            // Check if offers is a single offer object (associative array) or array of offers
            if (isset($offers['@type']) || isset($offers['price'])) {
                $offers = [$offers];
            }

            foreach ($offers as $offer) {
                $availability = $offer['availability'] ?? null;
                if ($availability !== null) {
                    $availability = strtolower($availability);
                    if (str_contains($availability, 'instock') || str_contains($availability, 'in_stock')) {
                        return true;
                    }
                    if (str_contains($availability, 'outofstock') || str_contains($availability, 'out_of_stock')) {
                        return false;
                    }
                }
            }
        }

        // Check DOM for out of stock indicators
        $outOfStockSelectors = [
            '[data-test="out-of-stock"]',
            '[data-testid="out-of-stock"]',
            '.out-of-stock',
            '.sold-out',
            '.unavailable',
            '.product--unavailable',
        ];

        foreach ($outOfStockSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    return false;
                }
            } catch (\Exception $e) {
                // Continue checking
            }
        }

        // Check for in stock indicators
        $inStockSelectors = [
            '[data-test="in-stock"]',
            '[data-testid="in-stock"]',
            '.in-stock',
            '.available',
            '.add-to-trolley:not([disabled])',
            'button[data-test="add-to-trolley"]:not([disabled])',
        ];

        foreach ($inStockSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    return true;
                }
            } catch (\Exception $e) {
                // Continue checking
            }
        }

        // Default to in stock
        return true;
    }

    /**
     * Extract external product ID.
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    public function extractExternalId(string $url, ?Crawler $crawler = null, array $jsonLdData = []): ?string
    {
        // Try URL pattern first: /ecom/products/{product-slug}/{product-id}
        if (preg_match('/\/ecom\/products\/[a-z0-9-]+\/([a-z0-9-]+)/i', $url, $matches)) {
            return $matches[1];
        }

        // Try JSON-LD SKU
        if (! empty($jsonLdData['sku'])) {
            return $jsonLdData['sku'];
        }

        // Try JSON-LD offers SKU
        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];

            // Check if offers is a single offer object (associative array) or array of offers
            if (isset($offers['@type']) || isset($offers['price'])) {
                $offers = [$offers];
            }

            foreach ($offers as $offer) {
                if (! empty($offer['sku'])) {
                    return $offer['sku'];
                }
            }
        }

        // Try DOM selectors
        if ($crawler !== null) {
            $idSelectors = [
                '[data-product-id]',
                '[data-sku]',
                '[data-lineid]',
                '[data-productid]',
                'input[name="product_id"]',
            ];

            foreach ($idSelectors as $selector) {
                try {
                    $element = $crawler->filter($selector);
                    if ($element->count() > 0) {
                        $id = $element->first()->attr('data-product-id')
                            ?? $element->first()->attr('data-sku')
                            ?? $element->first()->attr('data-lineid')
                            ?? $element->first()->attr('data-productid')
                            ?? $element->first()->attr('value');

                        if ($id !== null && ! empty(trim($id))) {
                            return trim($id);
                        }
                    }
                } catch (\Exception $e) {
                    // Continue
                }
            }
        }

        return null;
    }

    /**
     * Extract category.
     */
    private function extractCategory(Crawler $crawler, string $url): ?string
    {
        return $this->categoryExtractor->extractFromBreadcrumbs($crawler)
            ?? $this->categoryExtractor->extractFromUrl($url);
    }

    /**
     * Extract ingredients.
     */
    private function extractIngredients(Crawler $crawler): ?string
    {
        $selectors = [
            '[data-test="ingredients"]',
            '[data-testid="ingredients"]',
            '.product-info-block--ingredients .product-info-block__content',
            '.ingredients',
            '.product-ingredients',
            '#ingredients',
            '.composition',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $ingredients = trim($element->first()->text());
                    if (! empty($ingredients)) {
                        return $ingredients;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("WaitroseProductDetailsExtractor: Ingredients selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Parse a price string and convert to pence.
     */
    public function parsePriceToPence(string $priceText): ?int
    {
        $priceText = trim($priceText);

        if (empty($priceText)) {
            return null;
        }

        // Handle pence format (e.g., "99p", "1299p")
        if (preg_match('/^(\d+)p$/i', $priceText, $matches)) {
            return (int) $matches[1];
        }

        // Remove currency symbols and whitespace
        $cleaned = preg_replace('/[£$€\s,]/', '', $priceText);

        // Handle decimal format (e.g., "12.99", "12,99")
        if (preg_match('/^(\d+)[.,](\d{1,2})$/', $cleaned, $matches)) {
            $pounds = (int) $matches[1];
            $pence = (int) str_pad($matches[2], 2, '0');

            return ($pounds * 100) + $pence;
        }

        // Handle whole number
        if (preg_match('/^(\d+)$/', $cleaned, $matches)) {
            $value = (int) $matches[1];

            return $value < 100 ? $value * 100 : $value;
        }

        return null;
    }
}
