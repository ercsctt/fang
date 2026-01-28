<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Ocado;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Concerns\ExtractsJsonLd;
use App\Crawler\Services\CategoryExtractor;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class OcadoProductDetailsExtractor implements ExtractorInterface
{
    use ExtractsJsonLd;

    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    /**
     * Weight conversion factors to grams.
     */
    private const WEIGHT_TO_GRAMS = [
        'kg' => 1000,
        'kilogram' => 1000,
        'kilograms' => 1000,
        'g' => 1,
        'gram' => 1,
        'grams' => 1,
        'ml' => 1,
        'millilitre' => 1,
        'millilitres' => 1,
        'milliliter' => 1,
        'milliliters' => 1,
        'l' => 1000,
        'ltr' => 1000,
        'litre' => 1000,
        'litres' => 1000,
        'liter' => 1000,
        'liters' => 1000,
        'lb' => 454,
        'lbs' => 454,
        'pound' => 454,
        'pounds' => 454,
        'oz' => 28,
        'ounce' => 28,
        'ounces' => 28,
    ];

    /**
     * Get all known brands for Ocado (core brands + Ocado-specific brands).
     *
     * @return array<string>
     */
    private function getKnownBrands(): array
    {
        return array_merge(
            config('brands.known_brands', []),
            config('brands.retailer_specific.ocado', [])
        );
    }

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Check for blocked page
        if ($this->isBlockedPage($crawler, $html)) {
            Log::warning("OcadoProductDetailsExtractor: Blocked/CAPTCHA page detected at {$url}");

            return;
        }

        // Try to extract product data from JSON-LD first (most reliable)
        $jsonLdData = $this->extractJsonLd($crawler);

        $title = $this->extractTitle($crawler, $jsonLdData);
        $price = $this->extractPrice($crawler, $jsonLdData);

        if ($title === null) {
            Log::warning("OcadoProductDetailsExtractor: Could not extract title from {$url}");
        }

        if ($price === null) {
            Log::warning("OcadoProductDetailsExtractor: Could not extract price from {$url}");
        }

        $weightData = $this->extractWeightAndQuantity($title ?? '', $crawler, $jsonLdData);

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
                'retailer' => 'ocado',
                'rating_value' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
                'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
            ],
            barcode: $this->extractBarcode($crawler, $jsonLdData),
        );

        Log::info("OcadoProductDetailsExtractor: Successfully extracted product details from {$url}");
    }

    public function canHandle(string $url): bool
    {
        if (! str_contains($url, 'ocado.com')) {
            return false;
        }

        // Handle product pages: /products/{slug}-{sku}
        return (bool) preg_match('/\/products\/[a-z0-9-]+-\d+$/i', $url);
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
            'h1[data-testid="product-title"]',
            'h1.fop-title',
            '.product-title h1',
            '.productTitle h1',
            '[data-test="product-title"]',
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
                Log::debug("OcadoProductDetailsExtractor: Title selector {$selector} failed: {$e->getMessage()}");
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
            if (! is_array($offers)) {
                $offers = [$offers];
            }

            if (! empty($offers[0]['price'])) {
                $price = (float) $offers[0]['price'];

                return (int) round($price * 100);
            }
        }

        $selectors = [
            '[data-testid="product-price"]',
            '.fop-price',
            '.product-price',
            '.price-current',
            '[data-test="product-price"]',
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
                Log::debug("OcadoProductDetailsExtractor: Price selector {$selector} failed: {$e->getMessage()}");
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
            '.was-price',
            '.price-was',
            '.original-price',
            '.price-rrp',
            '[data-original-price]',
            's.price',
            'del.price',
            '.strikethrough-price',
            '.fop-price-was',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $dataPrice = $element->first()->attr('data-original-price');
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
                Log::debug("OcadoProductDetailsExtractor: Original price selector {$selector} failed: {$e->getMessage()}");
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
            '[data-testid="product-description"]',
            '.product-description',
            '.fop-description',
            '.description-content',
            '#product-description',
            '.product-info-description',
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
                Log::debug("OcadoProductDetailsExtractor: Description selector {$selector} failed: {$e->getMessage()}");
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
                '.fop-images img',
                '.product-image img',
                '.gallery img',
                '[data-product-image]',
                '.product-gallery img',
                '.carousel img',
                '.product-media img',
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
                    Log::debug("OcadoProductDetailsExtractor: Image selector {$selector} failed: {$e->getMessage()}");
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
            '[data-testid="product-brand"]',
            '.product-brand',
            '.brand-name',
            '[data-brand]',
            'a[href*="/brands/"]',
            '.fop-brand',
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
                Log::debug("OcadoProductDetailsExtractor: Brand selector {$selector} failed: {$e->getMessage()}");
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

        // Try to get weight from JSON-LD offers (often in SKU description)
        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];
            if (! is_array($offers)) {
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

        // Try DOM selectors
        if ($weight === null) {
            $weightSelectors = [
                '.product-weight',
                '[data-weight]',
                '.size-selector option:checked',
                '.weight-selector .selected',
                '.product-size',
                '.fop-catchweight',
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
                    Log::debug("OcadoProductDetailsExtractor: Weight selector {$selector} failed: {$e->getMessage()}");
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
        $pattern = '/(\d+(?:[.,]\d+)?)\s*(kg|kilogram|kilograms|g|gram|grams|ml|millilitre|millilitres|milliliter|milliliters|l|ltr|litre|litres|liter|liters|lb|lbs|pound|pounds|oz|ounce|ounces)\b/i';

        if (preg_match($pattern, $text, $matches)) {
            $value = (float) str_replace(',', '.', $matches[1]);
            $unit = strtolower($matches[2]);

            $multiplier = self::WEIGHT_TO_GRAMS[$unit] ?? 1;

            return (int) round($value * $multiplier);
        }

        return null;
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
            if (! is_array($offers)) {
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
            '[data-stock-status="out"]',
            '.sold-out',
            '.unavailable',
            '[data-testid="out-of-stock"]',
            '.fop-out-of-stock',
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
            '[data-stock-status="in"]',
            '.available',
            '[data-testid="in-stock"]',
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
        // Try URL pattern first: /products/{slug}-{sku}
        if (preg_match('/\/products\/[a-z0-9-]+-(\d+)$/i', $url, $matches)) {
            return $matches[1];
        }

        // Try JSON-LD SKU
        if (! empty($jsonLdData['sku'])) {
            return $jsonLdData['sku'];
        }

        // Try JSON-LD offers SKU
        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];
            if (! is_array($offers)) {
                $offers = [$offers];
            }
            if (! empty($offers[0]['sku'])) {
                return $offers[0]['sku'];
            }
        }

        // Try DOM selectors
        if ($crawler !== null) {
            $idSelectors = [
                '[data-product-id]',
                '[data-sku]',
                '[data-product-code]',
                'input[name="product_id"]',
                '[data-fop-id]',
            ];

            foreach ($idSelectors as $selector) {
                try {
                    $element = $crawler->filter($selector);
                    if ($element->count() > 0) {
                        $id = $element->first()->attr('data-product-id')
                            ?? $element->first()->attr('data-sku')
                            ?? $element->first()->attr('data-product-code')
                            ?? $element->first()->attr('data-fop-id')
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
            '.ingredients',
            '[data-ingredients]',
            '.product-ingredients',
            '#ingredients',
            '.composition',
            '[data-testid="ingredients"]',
            '.ingredient-list',
            '.fop-ingredients',
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
                Log::debug("OcadoProductDetailsExtractor: Ingredients selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract barcode/EAN.
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    private function extractBarcode(Crawler $crawler, array $jsonLdData): ?string
    {
        // Try JSON-LD gtin/ean
        if (! empty($jsonLdData['gtin'])) {
            return $jsonLdData['gtin'];
        }
        if (! empty($jsonLdData['gtin13'])) {
            return $jsonLdData['gtin13'];
        }
        if (! empty($jsonLdData['ean'])) {
            return $jsonLdData['ean'];
        }

        // Try DOM selectors
        $selectors = [
            '[data-barcode]',
            '[data-ean]',
            '[data-gtin]',
            '.barcode',
            '.ean',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $barcode = $element->first()->attr('data-barcode')
                        ?? $element->first()->attr('data-ean')
                        ?? $element->first()->attr('data-gtin')
                        ?? trim($element->first()->text());

                    if ($barcode !== null && ! empty(trim($barcode))) {
                        return trim($barcode);
                    }
                }
            } catch (\Exception $e) {
                // Continue
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
