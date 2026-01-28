<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Sainsburys;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductDetails;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class SainsburysProductDetailsExtractor implements ExtractorInterface
{
    /**
     * Weight conversion factors to grams.
     */
    private const WEIGHT_TO_GRAMS = [
        'kg' => 1000,
        'g' => 1,
        'ml' => 1,
        'l' => 1000,
        'ltr' => 1000,
        'litre' => 1000,
        'litres' => 1000,
        'lb' => 453.592,
        'oz' => 28.3495,
    ];

    /**
     * Get all known brands for Sainsbury's (core brands + Sainsbury's-specific brands).
     *
     * @return array<string>
     */
    private function getKnownBrands(): array
    {
        return array_merge(
            config('brands.known_brands', []),
            config('brands.retailer_specific.sainsburys', [])
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
            Log::warning("SainsburysProductDetailsExtractor: Could not extract title from {$url}");
        }

        if ($price === null) {
            Log::warning("SainsburysProductDetailsExtractor: Could not extract price from {$url}");
        }

        $weightData = $this->extractWeightAndQuantity($title ?? '', $crawler, $jsonLdData);

        // Extract Nectar price separately (loyalty card price)
        $nectarPrice = $this->extractNectarPrice($crawler);

        // Extract multi-buy offer info
        $multiBuyOffer = $this->extractMultiBuyOffer($crawler);

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
                'retailer' => 'sainsburys',
                'nectar_price_pence' => $nectarPrice,
                'multi_buy_offer' => $multiBuyOffer,
                'rating_value' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
                'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
            ],
        );

        Log::info("SainsburysProductDetailsExtractor: Successfully extracted product details from {$url}");
    }

    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'sainsburys.co.uk')) {
            // Handle product URLs
            return (bool) preg_match('/\/gol-ui\/product\/|\/product\/[a-z0-9-]+-\d+|\/shop\/gb\/groceries\/[^\/]+\/[a-z0-9-]+--\d+/i', $url);
        }

        return false;
    }

    /**
     * Extract JSON-LD structured data from the page.
     *
     * @return array<string, mixed>
     */
    private function extractJsonLd(Crawler $crawler): array
    {
        try {
            $scripts = $crawler->filter('script[type="application/ld+json"]');

            foreach ($scripts as $script) {
                $content = $script->textContent;
                $data = json_decode($content, true);

                if ($data === null) {
                    continue;
                }

                // Handle @graph format
                if (isset($data['@graph'])) {
                    foreach ($data['@graph'] as $item) {
                        if (($item['@type'] ?? null) === 'Product') {
                            return $item;
                        }
                    }
                }

                // Direct Product type
                if (($data['@type'] ?? null) === 'Product') {
                    return $data;
                }
            }
        } catch (\Exception $e) {
            Log::debug("SainsburysProductDetailsExtractor: Failed to extract JSON-LD: {$e->getMessage()}");
        }

        return [];
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
            '[data-test-id="pd-product-title"]',
            '[data-testid="pd-product-title"]',
            '.pd__header h1',
            '.product-details__title',
            '.productNameTitle',
            'h1.pd__name',
            '[data-test-id="product-title"]',
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
                Log::debug("SainsburysProductDetailsExtractor: Title selector {$selector} failed: {$e->getMessage()}");
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
            // Single offer has @type or price keys at top level
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
            '[data-test-id="pd-retail-price"]',
            '[data-testid="pd-retail-price"]',
            '.pd__cost__retail-price',
            '.pricePerUnit',
            '.product-details__price',
            '[data-test-id="price"]',
            '.pd__cost .pd__cost__retail-price',
            '.pricing__now',
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
                Log::debug("SainsburysProductDetailsExtractor: Price selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract Nectar promotional price.
     */
    private function extractNectarPrice(Crawler $crawler): ?int
    {
        $selectors = [
            '[data-test-id="pd-nectar-price"]',
            '[data-testid="pd-nectar-price"]',
            '.pd__cost__nectar-price',
            '.nectarPrice',
            '[data-test-id="nectar-price"]',
            '.nectar-price__value',
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
                Log::debug("SainsburysProductDetailsExtractor: Nectar price selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract multi-buy offer information.
     *
     * @return array{text: string, quantity: int|null, price: int|null}|null
     */
    private function extractMultiBuyOffer(Crawler $crawler): ?array
    {
        $selectors = [
            '[data-test-id="pd-offer"]',
            '[data-testid="pd-offer"]',
            '.pd__cost__offer',
            '.offer-content',
            '.product-offer',
            '.multi-buy',
            '[data-test-id="multi-buy-offer"]',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $offerText = trim($element->first()->text());
                    if (! empty($offerText)) {
                        return $this->parseMultiBuyOffer($offerText);
                    }
                }
            } catch (\Exception $e) {
                Log::debug("SainsburysProductDetailsExtractor: Multi-buy selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Parse multi-buy offer text into structured data.
     *
     * @return array{text: string, quantity: int|null, price: int|null}
     */
    private function parseMultiBuyOffer(string $text): array
    {
        $result = [
            'text' => $text,
            'quantity' => null,
            'price' => null,
        ];

        // Pattern: "2 for £X" or "3 for £X.XX"
        if (preg_match('/(\d+)\s+for\s+£?(\d+(?:\.\d{2})?)/', $text, $matches)) {
            $result['quantity'] = (int) $matches[1];
            $result['price'] = (int) round((float) $matches[2] * 100);
        }

        // Pattern: "Buy 2 get 1 free"
        if (preg_match('/buy\s+(\d+)\s+get\s+(\d+)\s+free/i', $text, $matches)) {
            $result['quantity'] = (int) $matches[1] + (int) $matches[2];
        }

        return $result;
    }

    /**
     * Extract original/was price in pence.
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    private function extractOriginalPrice(Crawler $crawler, array $jsonLdData): ?int
    {
        $selectors = [
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
                Log::debug("SainsburysProductDetailsExtractor: Original price selector {$selector} failed: {$e->getMessage()}");
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
            '[data-test-id="product-description"]',
            '[data-testid="product-description"]',
            '.pd__description',
            '.productDescription',
            '.product-info__description',
            '#product-description',
            '.pd__content__description',
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
                Log::debug("SainsburysProductDetailsExtractor: Description selector {$selector} failed: {$e->getMessage()}");
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
                $images[] = $this->upgradeImageUrl($jsonImages);
            } elseif (is_array($jsonImages)) {
                foreach ($jsonImages as $img) {
                    if (is_string($img)) {
                        $images[] = $this->upgradeImageUrl($img);
                    } elseif (is_array($img) && isset($img['url'])) {
                        $images[] = $this->upgradeImageUrl($img['url']);
                    }
                }
            }
        }

        // If no JSON-LD images, try DOM selectors
        if (empty($images)) {
            $selectors = [
                '[data-test-id="pd-image"] img',
                '[data-testid="pd-image"] img',
                '.pd__image img',
                '.productImage img',
                '.product-image-wrapper img',
                '.slick-slide img',
                '.pd__gallery img',
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
                                    $images[] = $this->upgradeImageUrl($src);
                                }
                            }
                        });
                    }
                } catch (\Exception $e) {
                    Log::debug("SainsburysProductDetailsExtractor: Image selector {$selector} failed: {$e->getMessage()}");
                }
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * Upgrade image URL to higher resolution version.
     */
    private function upgradeImageUrl(string $url): string
    {
        // Sainsbury's image URLs can be upgraded by modifying size parameters
        // e.g., ?w=100 to ?w=800
        if (preg_match('/\?.*w=\d+/', $url)) {
            return preg_replace('/(\?.*w=)\d+/', '$1800', $url);
        }

        // Add width parameter if not present
        if (! str_contains($url, '?')) {
            return $url.'?w=800';
        }

        return $url;
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
            '[data-test-id="pd-brand"]',
            '[data-testid="pd-brand"]',
            '.pd__brand',
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
                Log::debug("SainsburysProductDetailsExtractor: Brand selector {$selector} failed: {$e->getMessage()}");
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
            'sainsburys',
            "sainsbury's",
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
            // Single offer has @type or price keys at top level
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
                '[data-test-id="pd-weight"]',
                '[data-testid="pd-weight"]',
                '.pd__weight',
                '.product-weight',
                '.product-size',
                '[data-weight]',
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
                    Log::debug("SainsburysProductDetailsExtractor: Weight selector {$selector} failed: {$e->getMessage()}");
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
        $pattern = '/(\d+(?:[.,]\d+)?)\s*(kg|g|ml|l|ltr|litre|litres|lb|oz)\b/i';

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

            // Check if offers is a single offer object (associative array) or array of offers
            // Single offer has @type or price keys at top level
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
            '[data-test-id="pd-out-of-stock"]',
            '[data-testid="pd-out-of-stock"]',
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
            '[data-test-id="pd-add-to-basket"]',
            '[data-testid="pd-add-to-basket"]',
            '.add-to-basket:not([disabled])',
            '.in-stock',
            '.available',
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
     * Extract external product ID (Sainsbury's product code).
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    public function extractExternalId(string $url, ?Crawler $crawler = null, array $jsonLdData = []): ?string
    {
        // Try URL pattern first: /gol-ui/product/[product-name]--[product-code]
        if (preg_match('/--(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        // Alternative pattern: /product/[name]-[code]
        if (preg_match('/\/product\/[a-z0-9-]+-(\d+)/i', $url, $matches)) {
            return $matches[1];
        }

        // Try JSON-LD SKU
        if (! empty($jsonLdData['sku'])) {
            return $jsonLdData['sku'];
        }

        // Try JSON-LD productID
        if (! empty($jsonLdData['productID'])) {
            return $jsonLdData['productID'];
        }

        // Try JSON-LD offers SKU
        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];

            // Check if offers is a single offer object (associative array) or array of offers
            // Single offer has @type or price keys at top level
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
                '[data-product-uid]',
                '[data-sku]',
                '[data-test-id="product-id"]',
                'input[name="product_id"]',
            ];

            foreach ($idSelectors as $selector) {
                try {
                    $element = $crawler->filter($selector);
                    if ($element->count() > 0) {
                        $id = $element->first()->attr('data-product-id')
                            ?? $element->first()->attr('data-product-uid')
                            ?? $element->first()->attr('data-sku')
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
        // Try breadcrumbs
        $breadcrumbSelectors = [
            '[data-test-id="breadcrumb"] a',
            '[data-testid="breadcrumb"] a',
            '.breadcrumb a',
            '.breadcrumbs a',
            'nav.breadcrumb a',
            '.ln-c-breadcrumbs a',
        ];

        foreach ($breadcrumbSelectors as $selector) {
            try {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 1) {
                    $crumbs = $elements->each(fn (Crawler $node) => trim($node->text()));
                    $crumbs = array_filter($crumbs);
                    $crumbs = array_values($crumbs);

                    if (count($crumbs) >= 2) {
                        $categoryIndex = max(0, count($crumbs) - 2);
                        if (! in_array(strtolower($crumbs[$categoryIndex]), ['home', 'groceries', 'pets', ''])) {
                            return $crumbs[$categoryIndex];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::debug("SainsburysProductDetailsExtractor: Category breadcrumb selector {$selector} failed: {$e->getMessage()}");
            }
        }

        // Extract from URL
        if (preg_match('/\/(dog|cat|puppy|kitten)[-\/]?(food|treats)?/i', $url, $matches)) {
            $animal = ucfirst(strtolower($matches[1]));
            $type = isset($matches[2]) ? ucfirst(strtolower($matches[2])) : null;

            return $type ? "{$animal} {$type}" : $animal;
        }

        return null;
    }

    /**
     * Extract ingredients.
     */
    private function extractIngredients(Crawler $crawler): ?string
    {
        $selectors = [
            '[data-test-id="product-ingredients"]',
            '[data-testid="product-ingredients"]',
            '.pd__ingredients',
            '.ingredients',
            '.product-ingredients',
            '#ingredients',
            '.composition',
            '.productIngredients',
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
                Log::debug("SainsburysProductDetailsExtractor: Ingredients selector {$selector} failed: {$e->getMessage()}");
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
