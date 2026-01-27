<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Zooplus;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Concerns\ExtractsBarcode;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ZooplusProductDetailsExtractor implements ExtractorInterface
{
    use ExtractsBarcode;

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
    ];

    /**
     * Known pet food brands for extraction fallback.
     *
     * @var array<string>
     */
    private const KNOWN_BRANDS = [
        'Pedigree',
        'Whiskas',
        'Felix',
        'Iams',
        'Royal Canin',
        'Purina',
        'Harringtons',
        'Bakers',
        'Burns',
        'James Wellbeloved',
        'Lily\'s Kitchen',
        "Lily's Kitchen",
        'Forthglade',
        'Butcher\'s',
        "Butcher's",
        'Cesar',
        'Webbox',
        'Good Boy',
        'Dreamies',
        'Wagg',
        'Naturo',
        'AVA',
        'Applaws',
        'Canagan',
        'Orijen',
        'Acana',
        'Hill\'s',
        "Hill's",
        'Hills',
        'Eukanuba',
        'ProPlan',
        'Pro Plan',
        'Arden Grange',
        'Barking Heads',
        'Canidae',
        'Taste of the Wild',
        'Wellness',
        'Blue Buffalo',
        'Natures Menu',
        'Nature\'s Menu',
        "Nature's Menu",
        'Encore',
        'Thrive',
        'Scrumbles',
        'Edgard & Cooper',
        'Pooch & Mutt',
        'Winalot',
        'Chappie',
        'Friskies',
        'Gourmet',
        'Sheba',
        'Vet\'s Kitchen',
        "Vet's Kitchen",
        'HiLife',
        'Skinners',
        'Rocco',
        'Wolf of Wilderness',
        'Concept for Life',
        'zooplus',
        'Lukullus',
        'Greenwoods',
        'Cosma',
        'Crave',
        'Purizon',
        'Wild Freedom',
        'Feringa',
        'Animonda',
        'Smilla',
        'Carnilove',
        'Tribal',
        'Bozita',
        'Happy Dog',
        'Happy Cat',
        'Josera',
        'Sanabelle',
        'Wolfsblut',
    ];

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Try to extract product data from JSON-LD first (most reliable)
        $jsonLdData = $this->extractJsonLd($crawler);

        $title = $this->extractTitle($crawler, $jsonLdData);
        $price = $this->extractPrice($crawler, $jsonLdData);

        if ($title === null) {
            Log::warning("ZooplusProductDetailsExtractor: Could not extract title from {$url}");
        }

        if ($price === null) {
            Log::warning("ZooplusProductDetailsExtractor: Could not extract price from {$url}");
        }

        $weightData = $this->extractWeightAndQuantity($title ?? '', $crawler, $jsonLdData);
        $nutritionalInfo = $this->extractNutritionalInfo($crawler);

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
            nutritionalInfo: $nutritionalInfo,
            inStock: $this->extractStockStatus($crawler, $jsonLdData),
            stockQuantity: null,
            externalId: $this->extractExternalId($url, $crawler, $jsonLdData),
            category: $this->extractCategory($crawler, $url),
            metadata: [
                'source_url' => $url,
                'extracted_at' => now()->toIso8601String(),
                'retailer' => 'zooplus-uk',
                'rating_value' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
                'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
            ],
            barcode: $this->extractBarcode($crawler, $jsonLdData),
        );

        Log::info("ZooplusProductDetailsExtractor: Successfully extracted product details from {$url}");
    }

    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'zooplus.co.uk')) {
            // Handle product URLs: /shop/dogs/.../product-name_123456
            return (bool) preg_match('/\/shop\/dogs\/[a-z0-9_\/]+\/[a-z0-9-]+_(\d{4,})/i', $url);
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
            Log::debug("ZooplusProductDetailsExtractor: Failed to extract JSON-LD: {$e->getMessage()}");
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
            '[data-zta="productTitle"]',
            '[data-testid="product-title"]',
            '.product-details__title',
            '.ProductTitle',
            'h1[itemprop="name"]',
            '.product__name',
            '.productName',
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
                Log::debug("ZooplusProductDetailsExtractor: Title selector {$selector} failed: {$e->getMessage()}");
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

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    // Check for content attribute (meta tags)
                    $contentPrice = $element->first()->attr('content');
                    if ($contentPrice !== null) {
                        $price = $this->parsePriceToPence($contentPrice);
                        if ($price !== null) {
                            return $price;
                        }
                    }

                    // Check for data-price attribute
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
                Log::debug("ZooplusProductDetailsExtractor: Price selector {$selector} failed: {$e->getMessage()}");
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
                Log::debug("ZooplusProductDetailsExtractor: Original price selector {$selector} failed: {$e->getMessage()}");
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
                Log::debug("ZooplusProductDetailsExtractor: Description selector {$selector} failed: {$e->getMessage()}");
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
                '[data-zta="productImage"] img',
                '[data-testid="product-image"] img',
                '.product-image__main img',
                '.ProductImage img',
                '.product-gallery__image img',
                '.product-image img',
                '.product__images img',
                '[itemprop="image"]',
            ];

            foreach ($selectors as $selector) {
                try {
                    $elements = $crawler->filter($selector);
                    if ($elements->count() > 0) {
                        $elements->each(function (Crawler $node) use (&$images) {
                            $src = $node->attr('src')
                                ?? $node->attr('data-src')
                                ?? $node->attr('data-lazy-src')
                                ?? $node->attr('content');

                            if ($src && ! in_array($src, $images)) {
                                if (! str_contains($src, 'placeholder') && ! str_contains($src, 'loading')) {
                                    $images[] = $src;
                                }
                            }
                        });
                    }
                } catch (\Exception $e) {
                    Log::debug("ZooplusProductDetailsExtractor: Image selector {$selector} failed: {$e->getMessage()}");
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

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    // Check for nested name element
                    $nameElement = $element->filter('[itemprop="name"]');
                    if ($nameElement->count() > 0) {
                        $brand = trim($nameElement->first()->text());
                        if (! empty($brand)) {
                            return $brand;
                        }
                    }

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
                Log::debug("ZooplusProductDetailsExtractor: Brand selector {$selector} failed: {$e->getMessage()}");
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
        foreach (self::KNOWN_BRANDS as $brand) {
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
            'zooplus',
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
                '[data-zta="productSize"]',
                '[data-testid="product-weight"]',
                '.product-size',
                '.ProductSize',
                '.product-weight',
                '.product__weight',
                '[data-weight]',
                '.variant-selector__option--selected',
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
                    Log::debug("ZooplusProductDetailsExtractor: Weight selector {$selector} failed: {$e->getMessage()}");
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
        $pattern = '/(\d+(?:[.,]\d+)?)\s*(kg|g|ml|l|ltr|litre|litres)\b/i';

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
            '[data-zta="outOfStock"]',
            '[data-testid="out-of-stock"]',
            '.out-of-stock',
            '.sold-out',
            '.unavailable',
            '.product--unavailable',
            '.product-availability--unavailable',
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
            '[data-zta="addToCart"]',
            '[data-testid="in-stock"]',
            '.in-stock',
            '.available',
            '.add-to-basket:not([disabled])',
            'button[data-zta="addToCartButton"]:not([disabled])',
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
        // Try URL pattern first: /shop/dogs/.../product-name_123456
        if (preg_match('/_(\d{4,})(?:\?|$)/', $url, $matches)) {
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
                '[data-article-id]',
                '[data-productid]',
                'input[name="product_id"]',
            ];

            foreach ($idSelectors as $selector) {
                try {
                    $element = $crawler->filter($selector);
                    if ($element->count() > 0) {
                        $id = $element->first()->attr('data-product-id')
                            ?? $element->first()->attr('data-sku')
                            ?? $element->first()->attr('data-article-id')
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
        // Try breadcrumbs
        $breadcrumbSelectors = [
            '[data-zta="breadcrumb"] a',
            '.breadcrumb a',
            '.breadcrumbs a',
            '[data-testid="breadcrumb"] a',
            'nav.breadcrumb a',
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
                        if (! in_array(strtolower($crumbs[$categoryIndex]), ['home', 'shop', 'dogs', 'dog', ''])) {
                            return $crumbs[$categoryIndex];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::debug("ZooplusProductDetailsExtractor: Category breadcrumb selector {$selector} failed: {$e->getMessage()}");
            }
        }

        // Extract from URL
        if (preg_match('/\/shop\/dogs\/([\w_]+)/', $url, $matches)) {
            return str_replace('_', ' ', ucfirst($matches[1]));
        }

        return null;
    }

    /**
     * Extract ingredients.
     */
    private function extractIngredients(Crawler $crawler): ?string
    {
        $selectors = [
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
                Log::debug("ZooplusProductDetailsExtractor: Ingredients selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract nutritional information.
     *
     * Zooplus often has detailed nutrition info for pet food.
     *
     * @return array<string, mixed>|null
     */
    private function extractNutritionalInfo(Crawler $crawler): ?array
    {
        $nutritionalInfo = [];

        $selectors = [
            '[data-zta="analyticalConstituents"]',
            '[data-testid="nutritional-info"]',
            '.analytical-constituents',
            '.AnalyticalConstituents',
            '.nutritional-info',
            '.nutrition-table',
            '[data-tab="analytical"]',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    // Try to extract structured data from table rows
                    $rows = $element->filter('tr, .nutrition-row, li');
                    if ($rows->count() > 0) {
                        $rows->each(function (Crawler $row) use (&$nutritionalInfo) {
                            $cells = $row->filter('td, .nutrition-value, span');
                            if ($cells->count() >= 2) {
                                $key = trim($cells->eq(0)->text());
                                $value = trim($cells->eq(1)->text());
                                if (! empty($key) && ! empty($value)) {
                                    $nutritionalInfo[$key] = $value;
                                }
                            } else {
                                // Try to parse "Protein: 25%" format
                                $text = trim($row->text());
                                if (preg_match('/^([^:]+):\s*(.+)$/', $text, $matches)) {
                                    $nutritionalInfo[trim($matches[1])] = trim($matches[2]);
                                }
                            }
                        });
                    }

                    if (! empty($nutritionalInfo)) {
                        return $nutritionalInfo;
                    }

                    // Fall back to raw text
                    $rawText = trim($element->first()->text());
                    if (! empty($rawText)) {
                        return ['raw' => $rawText];
                    }
                }
            } catch (\Exception $e) {
                Log::debug("ZooplusProductDetailsExtractor: Nutritional info selector {$selector} failed: {$e->getMessage()}");
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
