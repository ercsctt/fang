<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Morrisons;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Concerns\ExtractsJsonLd;
use App\Crawler\Services\CategoryExtractor;
use App\Services\ProductNormalizer;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class MorrisonsProductDetailsExtractor implements ExtractorInterface
{
    use ExtractsJsonLd;

    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    /**
     * Weight conversion factors to grams.
     */

    /**
     * Get all known brands for Morrisons (core brands + Morrisons-specific brands).
     *
     * @return array<string>
     */
    private function getKnownBrands(): array
    {
        return array_merge(
            config('brands.known_brands', []),
            config('brands.retailer_specific.morrisons', [])
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
            Log::warning("MorrisonsProductDetailsExtractor: Could not extract title from {$url}");
        }

        if ($price === null) {
            Log::warning("MorrisonsProductDetailsExtractor: Could not extract price from {$url}");
        }

        $weightData = $this->extractWeightAndQuantity($title ?? '', $crawler, $jsonLdData);

        // Extract promotional prices separately
        $priceDroppedPrice = $this->extractPriceDroppedPrice($crawler);
        $myMorrisonsPrice = $this->extractMyMorrisonsPrice($crawler);

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
                'retailer' => 'morrisons',
                'price_dropped_pence' => $priceDroppedPrice,
                'my_morrisons_price_pence' => $myMorrisonsPrice,
                'rating_value' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
                'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
            ],
        );

        Log::info("MorrisonsProductDetailsExtractor: Successfully extracted product details from {$url}");
    }

    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'morrisons.com')) {
            // Handle product URLs: /products/[product-slug]/[SKU]
            return (bool) preg_match('/\/products\/[\w-]+\/\w+/', $url);
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
            'h1[data-test="product-title"]',
            '.pdp-main-details__title',
            '.product-title',
            'h1.product-name',
            '[data-testid="product-title"]',
            '.product-details h1',
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
                Log::debug("MorrisonsProductDetailsExtractor: Title selector {$selector} failed: {$e->getMessage()}");
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
            '.pdp-main-details__price-value',
            '.price--current',
            '.product-price__value',
            '[data-testid="product-price"]',
            '.price-value',
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
                Log::debug("MorrisonsProductDetailsExtractor: Price selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract Price Dropped promotional price.
     */
    private function extractPriceDroppedPrice(Crawler $crawler): ?int
    {
        $selectors = [
            '[data-test="price-dropped"]',
            '.price-dropped__value',
            '.offer-price--dropped',
            '.price--dropped',
            '[data-testid="price-dropped"]',
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
                Log::debug("MorrisonsProductDetailsExtractor: Price dropped selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract My Morrisons member price.
     */
    private function extractMyMorrisonsPrice(Crawler $crawler): ?int
    {
        $selectors = [
            '[data-test="my-morrisons-price"]',
            '.my-morrisons-price__value',
            '.member-price',
            '.loyalty-price',
            '[data-testid="my-morrisons-price"]',
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
                Log::debug("MorrisonsProductDetailsExtractor: My Morrisons price selector {$selector} failed: {$e->getMessage()}");
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
            '.price--was',
            '.was-price',
            '.price-was',
            '.original-price',
            '.strikethrough-price',
            's.price',
            'del.price',
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
                Log::debug("MorrisonsProductDetailsExtractor: Original price selector {$selector} failed: {$e->getMessage()}");
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
            '.pdp-description__content',
            '.product-description',
            '.product__description',
            '#product-description',
            '.product-info-block__content',
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
                Log::debug("MorrisonsProductDetailsExtractor: Description selector {$selector} failed: {$e->getMessage()}");
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
                '.pdp-main-details__image img',
                '.product-image img',
                '[data-test="product-image"] img',
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
                    Log::debug("MorrisonsProductDetailsExtractor: Image selector {$selector} failed: {$e->getMessage()}");
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
                Log::debug("MorrisonsProductDetailsExtractor: Brand selector {$selector} failed: {$e->getMessage()}");
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
            'morrisons',
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
                '.product-weight',
                '.product__weight',
                '[data-weight]',
                '.product-size',
                '.pdp-main-details__weight',
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
                    Log::debug("MorrisonsProductDetailsExtractor: Weight selector {$selector} failed: {$e->getMessage()}");
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
            '.out-of-stock',
            '[data-test="out-of-stock"]',
            '.sold-out',
            '.unavailable',
            '.product--unavailable',
            '[data-testid="out-of-stock"]',
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
            '.in-stock',
            '[data-test="in-stock"]',
            '.available',
            '[data-testid="in-stock"]',
            '.add-to-basket:not([disabled])',
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
     * Extract external product ID (Morrisons SKU).
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    public function extractExternalId(string $url, ?Crawler $crawler = null, array $jsonLdData = []): ?string
    {
        // Try URL pattern first: /products/[product-slug]/[SKU]
        if (preg_match('/\/products\/[\w-]+\/(\w+)/', $url, $matches)) {
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
                '[data-product-sku]',
                'input[name="product_id"]',
            ];

            foreach ($idSelectors as $selector) {
                try {
                    $element = $crawler->filter($selector);
                    if ($element->count() > 0) {
                        $id = $element->first()->attr('data-product-id')
                            ?? $element->first()->attr('data-sku')
                            ?? $element->first()->attr('data-product-sku')
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
            '.product-info-block--ingredients .product-info-block__content',
            '.ingredients',
            '.product-ingredients',
            '#ingredients',
            '.composition',
            '[data-testid="ingredients"]',
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
                Log::debug("MorrisonsProductDetailsExtractor: Ingredients selector {$selector} failed: {$e->getMessage()}");
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
