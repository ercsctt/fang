<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Asda;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductDetails;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AsdaProductDetailsExtractor implements ExtractorInterface
{
    /**
     * Weight conversion factors to grams.
     */
    private const WEIGHT_TO_GRAMS = [
        'kg' => 1000,
        'kilograms' => 1000,
        'kilogram' => 1000,
        'g' => 1,
        'grams' => 1,
        'gram' => 1,
        'ml' => 1,
        'millilitres' => 1,
        'milliliters' => 1,
        'l' => 1000,
        'ltr' => 1000,
        'litre' => 1000,
        'litres' => 1000,
        'liter' => 1000,
        'liters' => 1000,
        'lb' => 454,
        'lbs' => 454,
        'pounds' => 454,
        'oz' => 28,
        'ounces' => 28,
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
        'ASDA',
        'Extra Special',
        'Smart Price',
        'Winalot',
        'Chappie',
        'Adventuros',
        'Dentalife',
        'Dentastix',
        'Frolic',
        'Markus Muhle',
        'Pero',
    ];

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Try to extract product data from JSON-LD first (most reliable)
        $jsonLdData = $this->extractJsonLd($crawler);

        $title = $this->extractTitle($crawler, $jsonLdData);
        $price = $this->extractPrice($crawler, $jsonLdData);

        if ($title === null) {
            Log::warning("AsdaProductDetailsExtractor: Could not extract title from {$url}");
        }

        if ($price === null) {
            Log::warning("AsdaProductDetailsExtractor: Could not extract price from {$url}");
        }

        $productId = $this->extractProductId($url, $crawler);
        $weightData = $this->extractWeightAndQuantity($title ?? '', $crawler);

        yield new ProductDetails(
            title: $title ?? 'Unknown Product',
            description: $this->extractDescription($crawler, $jsonLdData),
            brand: $this->extractBrand($crawler, $jsonLdData, $title),
            pricePence: $price ?? 0,
            originalPricePence: $this->extractOriginalPrice($crawler),
            currency: 'GBP',
            weightGrams: $weightData['weight'],
            quantity: $weightData['quantity'],
            images: $this->extractImages($crawler, $jsonLdData),
            ingredients: $this->extractIngredients($crawler),
            nutritionalInfo: null,
            inStock: $this->extractStockStatus($crawler),
            stockQuantity: null,
            externalId: $productId,
            category: $this->extractCategory($crawler),
            metadata: [
                'source_url' => $url,
                'extracted_at' => now()->toIso8601String(),
                'retailer' => 'asda',
                'product_id' => $productId,
                'rating_value' => $this->extractRating($crawler),
                'review_count' => $this->extractReviewCount($crawler),
                'price_per_unit' => $this->extractPricePerUnit($crawler),
                'asda_rewards_price' => $this->extractAsdaRewardsPrice($crawler),
                'rollback_price' => $this->extractRollbackPrice($crawler),
            ],
        );

        Log::info("AsdaProductDetailsExtractor: Successfully extracted product details from {$url}");
    }

    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'groceries.asda.com')) {
            // Handle product detail pages: /product/[name]/[id] or /product/[id]
            return preg_match('/\/product\/(?:[a-z0-9-]+\/)?(\d+)(?:\/|$|\?)/i', $url) === 1;
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
            Log::debug("AsdaProductDetailsExtractor: Failed to extract JSON-LD: {$e->getMessage()}");
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

        // Asda-specific selectors
        $selectors = [
            'h1[data-auto-id="pdp-product-title"]',
            '.pdp-main-details__title',
            '[data-testid="product-title"]',
            '.co-product__title',
            'h1.product-title',
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
                Log::debug("AsdaProductDetailsExtractor: Title selector {$selector} failed: {$e->getMessage()}");
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

            // Check if offers is a single offer object or array
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

        // Asda-specific price selectors
        $selectors = [
            // Current selling price
            '[data-auto-id="pdp-price"] strong',
            '.pdp-main-details__price strong',
            '.co-product__price strong',
            '[data-testid="product-price"]',
            '.product-price .price',
            // Rollback/sale price (takes precedence)
            '.rollback-price',
            '.sale-price',
            '.offer-price',
            // Standard price fallback
            '.price strong',
            '.price',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $priceText = trim($element->first()->text());
                    $price = $this->parsePriceToPence($priceText);
                    if ($price !== null && $price > 0) {
                        return $price;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("AsdaProductDetailsExtractor: Price selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract original/was price in pence.
     */
    private function extractOriginalPrice(Crawler $crawler): ?int
    {
        $selectors = [
            // Asda "was" price selectors
            '[data-auto-id="pdp-was-price"]',
            '.pdp-main-details__was-price',
            '.was-price',
            '.price-was',
            '.original-price',
            's.price',
            'del.price',
            '.price-strikethrough',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $priceText = trim($element->first()->text());
                    $price = $this->parsePriceToPence($priceText);
                    if ($price !== null && $price > 0) {
                        return $price;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("AsdaProductDetailsExtractor: Original price selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract Asda Rewards price if different.
     */
    private function extractAsdaRewardsPrice(Crawler $crawler): ?int
    {
        $selectors = [
            '.asda-rewards-price',
            '[data-auto-id="asda-rewards-price"]',
            '.rewards-price',
            '.loyalty-price',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $priceText = trim($element->first()->text());
                    $price = $this->parsePriceToPence($priceText);
                    if ($price !== null && $price > 0) {
                        return $price;
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Extract Rollback/promotion price.
     */
    private function extractRollbackPrice(Crawler $crawler): ?int
    {
        try {
            $rollbackElement = $crawler->filter('.rollback, [data-auto-id="rollback-price"]');
            if ($rollbackElement->count() > 0) {
                $priceText = trim($rollbackElement->first()->text());

                return $this->parsePriceToPence($priceText);
            }
        } catch (\Exception $e) {
            // Continue
        }

        return null;
    }

    /**
     * Extract price per unit (e.g., "£2.50 per kg").
     */
    private function extractPricePerUnit(Crawler $crawler): ?string
    {
        $selectors = [
            '[data-auto-id="pdp-price-per-unit"]',
            '.pdp-main-details__price-per-unit',
            '.co-product__price-per-unit',
            '.price-per-unit',
            '.unit-price',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    if (! empty($text)) {
                        return $text;
                    }
                }
            } catch (\Exception $e) {
                // Continue
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

        // Asda product description selectors
        $selectors = [
            '[data-auto-id="pdp-description"]',
            '.pdp-description__content',
            '.product-description',
            '.co-product__description',
            '[data-testid="product-description"]',
            '#product-description',
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
                Log::debug("AsdaProductDetailsExtractor: Description selector {$selector} failed: {$e->getMessage()}");
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

        // Asda image selectors
        $selectors = [
            '[data-auto-id="pdp-image"] img',
            '.pdp-image-carousel img',
            '.product-image img',
            '.co-product__image img',
            '.gallery-image img',
            '[data-testid="product-image"] img',
        ];

        foreach ($selectors as $selector) {
            try {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 0) {
                    $elements->each(function (Crawler $node) use (&$images) {
                        // Try various image source attributes
                        $src = $node->attr('src')
                            ?? $node->attr('data-src')
                            ?? $node->attr('data-lazy-src');

                        if ($src && ! in_array($src, $images)) {
                            // Skip placeholder/loading images
                            if (! str_contains($src, 'placeholder') && ! str_contains($src, 'loading') && ! str_contains($src, 'data:')) {
                                $images[] = $this->upgradeImageUrl($src);
                            }
                        }
                    });
                }
            } catch (\Exception $e) {
                Log::debug("AsdaProductDetailsExtractor: Image selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * Upgrade Asda image URL to larger size if possible.
     */
    private function upgradeImageUrl(string $url): string
    {
        // Asda images often have size parameters that can be adjusted
        // e.g., ?w=200&h=200 can be changed to larger sizes
        if (str_contains($url, 'asda-groceries.co.uk') || str_contains($url, 'asda.com')) {
            // Remove small size constraints
            $url = preg_replace('/[?&]w=\d+/', '', $url);
            $url = preg_replace('/[?&]h=\d+/', '', $url);

            // Add larger size if possible
            if (! str_contains($url, '?')) {
                $url .= '?w=600&h=600';
            }
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

        // Asda-specific brand selectors
        $selectors = [
            '[data-auto-id="pdp-brand"]',
            '.pdp-main-details__brand',
            '.product-brand',
            '.co-product__brand',
            '[data-testid="product-brand"]',
            '.brand-name',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    if (! empty($text)) {
                        return $text;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("AsdaProductDetailsExtractor: Brand selector {$selector} failed: {$e->getMessage()}");
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

        // Asda titles often start with the brand
        $words = explode(' ', $title);
        if (count($words) > 1) {
            $firstWord = $words[0];
            if ($this->looksLikeBrand($firstWord)) {
                // Check if first two words might be the brand
                if (count($words) > 2 && $this->looksLikeBrand($words[1])) {
                    return $words[0].' '.$words[1];
                }

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
            'the',
            'a',
            'an',
            'new',
            'best',
            'premium',
            'deluxe',
            'original',
            'natural',
            'organic',
            'pack',
            'size',
            'dog',
            'cat',
            'pet',
            'puppy',
            'kitten',
            'adult',
            'senior',
            'food',
            'treats',
            'dry',
            'wet',
            'complete',
            'asda',
            'extra',
            'smart',
        ];

        return ! empty($text)
            && strlen($text) > 1
            && ! in_array(strtolower($text), $skipWords)
            && preg_match('/^[A-Z]/', $text);
    }

    /**
     * Extract weight and quantity.
     *
     * @return array{weight: int|null, quantity: int|null}
     */
    private function extractWeightAndQuantity(string $title, Crawler $crawler): array
    {
        $weight = null;
        $quantity = null;

        // Try to get from product details/attributes
        $weightSelectors = [
            '[data-auto-id="pdp-weight"]',
            '.pdp-main-details__weight',
            '.product-weight',
            '.product-size',
            '[data-testid="product-weight"]',
        ];

        foreach ($weightSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $element->first()->text();
                    $weight = $this->parseWeight($text);
                    if ($weight !== null) {
                        break;
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        // Fall back to parsing from title
        if ($weight === null) {
            $weight = $this->parseWeight($title);
        }

        // Extract quantity/pack size
        if (preg_match('/(\d+)\s*(?:pack|count|pcs|pieces|tins|pouches|sachets|bags|cans)\b/i', $title, $matches)) {
            $quantity = (int) $matches[1];
        }

        // Handle "12 x 400g" or "12x400g" pattern
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
        // Match patterns like "2.5kg", "400g", "500ml", "1l", "1.5 kg", "5 lb"
        $pattern = '/(\d+(?:[.,]\d+)?)\s*(kg|kilograms?|g|grams?|ml|millilitres?|milliliters?|l|ltr|litres?|liters?|lb|lbs|pounds?|oz|ounces?)\b/i';

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
     */
    private function extractStockStatus(Crawler $crawler): bool
    {
        // Check for out of stock indicators
        $outOfStockSelectors = [
            '.out-of-stock',
            '[data-auto-id="out-of-stock"]',
            '.unavailable',
            '.sold-out',
            '[data-testid="out-of-stock"]',
        ];

        foreach ($outOfStockSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    return false;
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        // Check for explicit in stock indicator
        $inStockSelectors = [
            '.in-stock',
            '[data-auto-id="in-stock"]',
            '.available',
        ];

        foreach ($inStockSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    return true;
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        // Check for add to trolley button
        try {
            $addButton = $crawler->filter('[data-auto-id="add-to-trolley"], .add-to-trolley, [data-testid="add-to-basket"]');
            if ($addButton->count() > 0) {
                return true;
            }
        } catch (\Exception $e) {
            // Continue
        }

        // Default to in stock
        return true;
    }

    /**
     * Extract product ID from URL or page.
     */
    public function extractProductId(string $url, ?Crawler $crawler = null): ?string
    {
        // Try URL patterns first
        if (preg_match('/\/product\/(?:[a-z0-9-]+\/)?(\d+)(?:\/|$|\?)/i', $url, $matches)) {
            return $matches[1];
        }

        // Try to find product ID in page
        if ($crawler !== null) {
            try {
                // Check for data attributes
                $productElement = $crawler->filter('[data-product-id], [data-sku-id], [data-item-id]');
                if ($productElement->count() > 0) {
                    $id = $productElement->first()->attr('data-product-id')
                        ?? $productElement->first()->attr('data-sku-id')
                        ?? $productElement->first()->attr('data-item-id');

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

    /**
     * Extract category from breadcrumbs.
     */
    private function extractCategory(Crawler $crawler): ?string
    {
        try {
            $breadcrumbs = $crawler->filter('[data-auto-id="breadcrumb"] a, .breadcrumb a, .breadcrumbs a');
            if ($breadcrumbs->count() > 1) {
                $crumbs = $breadcrumbs->each(fn (Crawler $node) => trim($node->text()));
                $crumbs = array_filter($crumbs);
                $crumbs = array_values($crumbs);

                // Get the deepest relevant category
                if (count($crumbs) >= 2) {
                    // Return second to last (last is usually the current product)
                    $categoryIndex = count($crumbs) - 1;

                    return $crumbs[$categoryIndex];
                }
            }
        } catch (\Exception $e) {
            Log::debug("AsdaProductDetailsExtractor: Category extraction failed: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Extract ingredients from product details.
     */
    private function extractIngredients(Crawler $crawler): ?string
    {
        $selectors = [
            '[data-auto-id="pdp-ingredients"]',
            '.pdp-description__ingredients',
            '.ingredients',
            '.product-ingredients',
            '#ingredients',
            '.composition',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    if (! empty($text)) {
                        return $text;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("AsdaProductDetailsExtractor: Ingredients selector {$selector} failed: {$e->getMessage()}");
            }
        }

        // Try to find in product description
        try {
            $description = $crawler->filter('[data-auto-id="pdp-description"], .pdp-description');
            if ($description->count() > 0) {
                $text = $description->first()->text();
                // Look for ingredients section
                if (preg_match('/(?:ingredients|composition)[:\s]*([^.]+(?:\.[^.]+){0,5})/i', $text, $matches)) {
                    return trim($matches[1]);
                }
            }
        } catch (\Exception $e) {
            // Continue
        }

        return null;
    }

    /**
     * Extract rating value.
     */
    private function extractRating(Crawler $crawler): ?float
    {
        try {
            $ratingElement = $crawler->filter('[data-auto-id="pdp-rating"], .product-rating, .star-rating');
            if ($ratingElement->count() > 0) {
                $text = $ratingElement->first()->attr('data-rating')
                    ?? $ratingElement->first()->attr('aria-label')
                    ?? $ratingElement->first()->text();

                if (preg_match('/([\d.]+)\s*(?:out of\s*5|stars?|\/\s*5)/i', $text, $matches)) {
                    return (float) $matches[1];
                }

                if (preg_match('/^([\d.]+)$/', trim($text), $matches)) {
                    return (float) $matches[1];
                }
            }
        } catch (\Exception $e) {
            // Continue
        }

        return null;
    }

    /**
     * Extract review count.
     */
    private function extractReviewCount(Crawler $crawler): ?int
    {
        try {
            $reviewElement = $crawler->filter('[data-auto-id="pdp-review-count"], .review-count, .reviews-count');
            if ($reviewElement->count() > 0) {
                $text = $reviewElement->first()->text();
                if (preg_match('/([\d,]+)\s*(?:ratings?|reviews?)/i', $text, $matches)) {
                    return (int) str_replace(',', '', $matches[1]);
                }
                if (preg_match('/\(([\d,]+)\)/', $text, $matches)) {
                    return (int) str_replace(',', '', $matches[1]);
                }
            }
        } catch (\Exception $e) {
            // Continue
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
