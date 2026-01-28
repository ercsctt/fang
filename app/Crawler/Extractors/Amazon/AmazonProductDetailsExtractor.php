<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Amazon;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductDetails;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AmazonProductDetailsExtractor implements ExtractorInterface
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
     * Get all known brands for Amazon (core brands + Amazon-specific brands).
     *
     * @return array<string>
     */
    private function getKnownBrands(): array
    {
        return array_merge(
            config('brands.known_brands', []),
            config('brands.retailer_specific.amazon', [])
        );
    }

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Check for CAPTCHA or blocked page
        if ($this->isBlockedPage($crawler, $html)) {
            Log::warning("AmazonProductDetailsExtractor: Blocked/CAPTCHA page detected at {$url}");

            return;
        }

        // Try to extract product data from JSON-LD first (most reliable)
        $jsonLdData = $this->extractJsonLd($crawler);

        $title = $this->extractTitle($crawler, $jsonLdData);
        $price = $this->extractPrice($crawler, $jsonLdData);

        if ($title === null) {
            Log::warning("AmazonProductDetailsExtractor: Could not extract title from {$url}");
        }

        if ($price === null) {
            Log::warning("AmazonProductDetailsExtractor: Could not extract price from {$url}");
        }

        $asin = $this->extractAsin($url, $crawler);
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
            externalId: $asin,
            category: $this->extractCategory($crawler),
            metadata: [
                'source_url' => $url,
                'extracted_at' => now()->toIso8601String(),
                'retailer' => 'amazon-uk',
                'asin' => $asin,
                'rating_value' => $this->extractRating($crawler),
                'review_count' => $this->extractReviewCount($crawler),
                'subscribe_save_price' => $this->extractSubscribeAndSavePrice($crawler),
                'deal_badge' => $this->extractDealBadge($crawler),
                'prime_eligible' => $this->isPrimeEligible($crawler),
            ],
        );

        Log::info("AmazonProductDetailsExtractor: Successfully extracted product details from {$url}");
    }

    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'amazon.co.uk')) {
            // Handle product detail pages: /dp/ASIN or /gp/product/ASIN
            return preg_match('/\/dp\/[A-Z0-9]{10}(?:\/|$|\?)/i', $url) === 1
                || preg_match('/\/gp\/product\/[A-Z0-9]{10}(?:\/|$|\?)/i', $url) === 1;
        }

        return false;
    }

    /**
     * Check if the page is blocked or shows a CAPTCHA.
     */
    private function isBlockedPage(Crawler $crawler, string $html): bool
    {
        // Check for CAPTCHA
        if (str_contains($html, 'captcha') || str_contains($html, 'robot check')) {
            return true;
        }

        // Check for "Sorry" page
        try {
            $sorryTitle = $crawler->filter('title');
            if ($sorryTitle->count() > 0) {
                $title = strtolower($sorryTitle->text());
                if (str_contains($title, 'sorry') || str_contains($title, 'robot')) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Continue
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
            Log::debug("AmazonProductDetailsExtractor: Failed to extract JSON-LD: {$e->getMessage()}");
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

        // Amazon-specific selectors
        $selectors = [
            '#productTitle',
            '#title span',
            'h1.a-size-large',
            'h1[data-automation-id="title"]',
            '#titleSection #title',
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
                Log::debug("AmazonProductDetailsExtractor: Title selector {$selector} failed: {$e->getMessage()}");
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
            // A single offer will have @type or price at the top level
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

        // Amazon-specific price selectors (in order of preference)
        $selectors = [
            // Current deal/sale price
            '.priceToPay .a-offscreen',
            '.priceToPay span.a-price-whole',
            '#corePrice_feature_div .a-price .a-offscreen',
            '#corePriceDisplay_desktop_feature_div .a-price .a-offscreen',
            // Regular price display
            '#priceblock_ourprice',
            '#priceblock_dealprice',
            '#priceblock_saleprice',
            '.a-price .a-offscreen',
            'span[data-a-color="price"] .a-offscreen',
            // Fallback
            '.a-price-whole',
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
                Log::debug("AmazonProductDetailsExtractor: Price selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract original/RRP price in pence.
     */
    private function extractOriginalPrice(Crawler $crawler): ?int
    {
        $selectors = [
            // Amazon's "Was" price selectors
            '.basisPrice .a-offscreen',
            'span[data-a-strike="true"] .a-offscreen',
            '.a-text-strike .a-offscreen',
            '#listPrice',
            '#priceblock_listprice',
            '.a-price[data-a-strike="true"] .a-offscreen',
            // RRP
            '#rrp .a-offscreen',
            '.rrp .a-offscreen',
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
                Log::debug("AmazonProductDetailsExtractor: Original price selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract Subscribe & Save price in pence.
     */
    private function extractSubscribeAndSavePrice(Crawler $crawler): ?int
    {
        $selectors = [
            '#sns-base-price',
            '#subscriptionPrice',
            '.sns-price .a-offscreen',
            '#snsAccordionRow .a-offscreen',
            '[data-sns-price]',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $priceText = trim($element->first()->text());
                    if (empty($priceText)) {
                        $priceText = $element->first()->attr('data-sns-price') ?? '';
                    }
                    $price = $this->parsePriceToPence($priceText);
                    if ($price !== null && $price > 0) {
                        return $price;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("AmazonProductDetailsExtractor: S&S price selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract deal badge text if present.
     */
    private function extractDealBadge(Crawler $crawler): ?string
    {
        $selectors = [
            '#dealBadge_feature_div',
            '.dealBadge',
            '#deal_badge',
            '.a-badge-deal',
            '[data-feature-name="dealBadge"]',
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

        // Amazon product description selectors
        $selectors = [
            '#productDescription p',
            '#productDescription',
            '#feature-bullets ul',
            '#feature-bullets',
            '#aplus_feature_div',
            '#aplus3p_feature_div',
            '[data-feature-name="productDescription"]',
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
                Log::debug("AmazonProductDetailsExtractor: Description selector {$selector} failed: {$e->getMessage()}");
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
                        $images[] = $this->upgradeImageUrl($img);
                    } elseif (is_array($img) && isset($img['url'])) {
                        $images[] = $this->upgradeImageUrl($img['url']);
                    }
                }
            }
        }

        // Try Amazon's image gallery scripts
        try {
            $scripts = $crawler->filter('script');
            foreach ($scripts as $script) {
                $content = $script->textContent;
                if (str_contains($content, 'colorImages') || str_contains($content, 'imageGalleryData')) {
                    // Look for large image URLs in the script
                    if (preg_match_all('/"hiRes"\s*:\s*"([^"]+)"/', $content, $matches)) {
                        foreach ($matches[1] as $url) {
                            if (! in_array($url, $images) && str_contains($url, 'amazon')) {
                                $images[] = $url;
                            }
                        }
                    }
                    // Also check for large attribute
                    if (preg_match_all('/"large"\s*:\s*"([^"]+)"/', $content, $matches)) {
                        foreach ($matches[1] as $url) {
                            if (! in_array($url, $images) && str_contains($url, 'amazon')) {
                                $images[] = $this->upgradeImageUrl($url);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("AmazonProductDetailsExtractor: Image script parsing failed: {$e->getMessage()}");
        }

        // Fallback to DOM selectors
        if (empty($images)) {
            $selectors = [
                '#imgTagWrapperId img',
                '#landingImage',
                '#main-image',
                '.imgTagWrapper img',
                '#imageBlock img',
            ];

            foreach ($selectors as $selector) {
                try {
                    $elements = $crawler->filter($selector);
                    if ($elements->count() > 0) {
                        $elements->each(function (Crawler $node) use (&$images) {
                            // Try various image source attributes
                            $src = $node->attr('data-old-hires')
                                ?? $node->attr('data-a-dynamic-image')
                                ?? $node->attr('src');

                            if ($src && ! in_array($src, $images)) {
                                // Handle data-a-dynamic-image which contains JSON
                                if (str_starts_with($src, '{')) {
                                    $imageData = json_decode($src, true);
                                    if (is_array($imageData)) {
                                        foreach (array_keys($imageData) as $url) {
                                            if (! in_array($url, $images)) {
                                                $images[] = $this->upgradeImageUrl($url);
                                            }
                                        }
                                    }
                                } elseif (str_contains($src, 'amazon')) {
                                    $images[] = $this->upgradeImageUrl($src);
                                }
                            }
                        });
                    }
                } catch (\Exception $e) {
                    Log::debug("AmazonProductDetailsExtractor: Image selector {$selector} failed: {$e->getMessage()}");
                }
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * Upgrade Amazon image URL to larger size.
     */
    private function upgradeImageUrl(string $url): string
    {
        // Amazon image URLs have size indicators like _SX300_ or _SL1500_
        // Replace with larger size
        return preg_replace('/\._[A-Z]{2}\d+_\./', '._SL1500_.', $url) ?? $url;
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

        // Amazon-specific brand selectors
        $selectors = [
            '#bylineInfo',
            '.po-brand .a-span9',
            '#brand',
            'a#bylineInfo',
            '[data-feature-name="bylineInfo"]',
            'tr.po-brand td.a-span9',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    // Clean up "Visit the X Store" or "Brand: X" format
                    $text = preg_replace('/^(Visit the |Brand:\s*)/i', '', $text);
                    $text = preg_replace('/\s+Store$/i', '', $text);
                    if (! empty($text)) {
                        return $text;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("AmazonProductDetailsExtractor: Brand selector {$selector} failed: {$e->getMessage()}");
            }
        }

        // Try to extract from product details table
        try {
            $rows = $crawler->filter('#productDetails_techSpec_section_1 tr, #productDetails_detailBullets_sections1 tr');
            foreach ($rows as $row) {
                $rowCrawler = new Crawler($row);
                $label = $rowCrawler->filter('th, td:first-child')->text();
                if (stripos($label, 'brand') !== false) {
                    $value = $rowCrawler->filter('td:last-child, td:nth-child(2)')->text();

                    return trim($value);
                }
            }
        } catch (\Exception $e) {
            // Continue
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

        // Amazon titles often start with the brand
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

        // Try to get from product details table
        try {
            $rows = $crawler->filter('#productDetails_techSpec_section_1 tr, #detailBullets_feature_div li, .prodDetTable tr');
            foreach ($rows as $row) {
                $rowCrawler = new Crawler($row);
                $text = strtolower($rowCrawler->text());

                if (str_contains($text, 'weight') || str_contains($text, 'size')) {
                    $weight = $this->parseWeight($text);
                    if ($weight !== null) {
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            // Continue
        }

        // Try Amazon's specific size/weight display
        try {
            $sizeElement = $crawler->filter('#variation_size_name .selection, #twister_feature_div .selection');
            if ($sizeElement->count() > 0) {
                $weight = $this->parseWeight($sizeElement->first()->text());
            }
        } catch (\Exception $e) {
            // Continue
        }

        // Fall back to parsing from title
        if ($weight === null) {
            $weight = $this->parseWeight($title);
        }

        // Extract quantity/pack size
        if (preg_match('/(\d+)\s*(?:pack|count|pcs|pieces|tins|pouches|sachets|bags)\b/i', $title, $matches)) {
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
        // Check Amazon's availability section
        $selectors = [
            '#availability',
            '#availability span',
            '#outOfStock',
            '#add-to-cart-button',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = strtolower(trim($element->first()->text()));

                    // Check for out of stock indicators
                    if (str_contains($text, 'out of stock')
                        || str_contains($text, 'currently unavailable')
                        || str_contains($text, 'not available')) {
                        return false;
                    }

                    // Check for in stock indicators
                    if (str_contains($text, 'in stock')
                        || str_contains($text, 'available')
                        || str_contains($text, 'add to basket')) {
                        return true;
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        // Check for add to cart button existence
        try {
            $addToCart = $crawler->filter('#add-to-cart-button, #addToCart');
            if ($addToCart->count() > 0) {
                return true;
            }
        } catch (\Exception $e) {
            // Continue
        }

        // Default to in stock
        return true;
    }

    /**
     * Check if product is Prime eligible.
     */
    private function isPrimeEligible(Crawler $crawler): bool
    {
        try {
            $primeElements = $crawler->filter('#prime-badge, .a-icon-prime, [data-feature-name="prime"]');

            return $primeElements->count() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Extract ASIN from URL or page.
     */
    public function extractAsin(string $url, ?Crawler $crawler = null): ?string
    {
        // Try URL patterns first
        if (preg_match('/\/dp\/([A-Z0-9]{10})(?:\/|$|\?)/i', $url, $matches)) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/\/gp\/product\/([A-Z0-9]{10})(?:\/|$|\?)/i', $url, $matches)) {
            return strtoupper($matches[1]);
        }

        // Try to find ASIN in page
        if ($crawler !== null) {
            try {
                // Check for ASIN in product details
                $rows = $crawler->filter('#detailBullets_feature_div li, #productDetails_techSpec_section_1 tr');
                foreach ($rows as $row) {
                    $text = (new Crawler($row))->text();
                    if (preg_match('/ASIN[:\s]+([A-Z0-9]{10})/i', $text, $matches)) {
                        return strtoupper($matches[1]);
                    }
                }

                // Check for data-asin attribute
                $asinElement = $crawler->filter('[data-asin]');
                if ($asinElement->count() > 0) {
                    $asin = $asinElement->first()->attr('data-asin');
                    if ($asin !== null && strlen($asin) === 10) {
                        return strtoupper($asin);
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
            $breadcrumbs = $crawler->filter('#wayfinding-breadcrumbs_feature_div a, .a-breadcrumb a');
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
            Log::debug("AmazonProductDetailsExtractor: Category extraction failed: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Extract ingredients from product details.
     */
    private function extractIngredients(Crawler $crawler): ?string
    {
        // Try important information section
        $selectors = [
            '#important-information',
            '#aplus_feature_div',
            '#productDescription',
            '.ingredients',
            '[data-feature-name="ingredients"]',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $element->first()->text();
                    // Look for ingredients section
                    if (preg_match('/(?:ingredients|composition)[:\s]*([^.]+(?:\.[^.]+){0,5})/i', $text, $matches)) {
                        return trim($matches[1]);
                    }
                }
            } catch (\Exception $e) {
                Log::debug("AmazonProductDetailsExtractor: Ingredients selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract rating value.
     */
    private function extractRating(Crawler $crawler): ?float
    {
        try {
            $ratingElement = $crawler->filter('#acrPopover, .a-icon-star .a-icon-alt, [data-action="acrStarsLink-click-metrics"]');
            if ($ratingElement->count() > 0) {
                $text = $ratingElement->first()->attr('title') ?? $ratingElement->first()->text();
                if (preg_match('/([\d.]+)\s*out of\s*5/i', $text, $matches)) {
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
            $reviewElement = $crawler->filter('#acrCustomerReviewText, #acrCustomerReviewLink');
            if ($reviewElement->count() > 0) {
                $text = $reviewElement->first()->text();
                if (preg_match('/([\d,]+)\s*(?:ratings?|reviews?|customer)/i', $text, $matches)) {
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
