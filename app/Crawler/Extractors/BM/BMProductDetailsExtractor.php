<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\BM;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Concerns\ExtractsBarcode;
use App\Crawler\Services\CategoryExtractor;
use App\Services\ProductNormalizer;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class BMProductDetailsExtractor implements ExtractorInterface
{
    use ExtractsBarcode;

    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        $title = $this->extractTitle($crawler);
        $price = $this->extractPrice($crawler);

        if ($title === null) {
            Log::warning("BMProductDetailsExtractor: Could not extract title from {$url}");
        }

        if ($price === null) {
            Log::warning("BMProductDetailsExtractor: Could not extract price from {$url}");
        }

        $weightData = $this->extractWeightAndQuantity($title ?? '', $crawler);

        yield new ProductDetails(
            title: $title ?? 'Unknown Product',
            description: $this->extractDescription($crawler),
            brand: $this->extractBrand($crawler, $title),
            pricePence: $price ?? 0,
            originalPricePence: $this->extractOriginalPrice($crawler),
            currency: 'GBP',
            weightGrams: $weightData['weight'],
            quantity: $weightData['quantity'],
            images: $this->extractImages($crawler),
            ingredients: $this->extractIngredients($crawler),
            nutritionalInfo: null,
            inStock: $this->extractStockStatus($crawler),
            stockQuantity: null,
            externalId: $this->extractExternalId($url, $crawler),
            category: $this->extractCategory($crawler, $url),
            metadata: [
                'source_url' => $url,
                'extracted_at' => now()->toIso8601String(),
                'retailer' => 'bm',
            ],
            barcode: $this->extractBarcode($crawler),
        );

        Log::info("BMProductDetailsExtractor: Successfully extracted product details from {$url}");
    }

    public function canHandle(string $url): bool
    {
        // Handle B&M product URLs
        if (str_contains($url, 'bmstores.co.uk')) {
            return str_contains($url, '/product/')
                || preg_match('/\/p\/\d+/', $url) === 1
                || preg_match('/\/pd\/[a-z0-9-]+/i', $url) === 1;
        }

        return false;
    }

    /**
     * Extract product title from the page.
     */
    private function extractTitle(Crawler $crawler): ?string
    {
        $selectors = [
            'h1.product-title',
            'h1[data-product-title]',
            '.product-name h1',
            '.pdp-title h1',
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
                Log::debug("BMProductDetailsExtractor: Selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract current price in pence.
     */
    private function extractPrice(Crawler $crawler): ?int
    {
        $selectors = [
            '.product-price',
            '[data-price]',
            '.price-current',
            '.pdp-price',
            '.price .current',
            '[data-testid="product-price"]',
            '.product-details .price',
            '.price',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    // First try data attribute
                    $dataPrice = $element->first()->attr('data-price');
                    if ($dataPrice !== null) {
                        $price = $this->parsePriceToPence($dataPrice);
                        if ($price !== null) {
                            return $price;
                        }
                    }

                    // Then try text content
                    $priceText = trim($element->first()->text());
                    $price = $this->parsePriceToPence($priceText);
                    if ($price !== null) {
                        return $price;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("BMProductDetailsExtractor: Price selector {$selector} failed: {$e->getMessage()}");
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
            '.was-price',
            '.price-was',
            '.original-price',
            '.price-rrp',
            '[data-original-price]',
            '.price .was',
            '.strikethrough-price',
            's.price',
            'del.price',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    // First try data attribute
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
                Log::debug("BMProductDetailsExtractor: Original price selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract product description.
     */
    private function extractDescription(Crawler $crawler): ?string
    {
        $selectors = [
            '.product-description',
            '[data-description]',
            '.description-content',
            '.pdp-description',
            '[data-testid="product-description"]',
            '.product-details .description',
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
                Log::debug("BMProductDetailsExtractor: Description selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract product images.
     *
     * @return array<string>
     */
    private function extractImages(Crawler $crawler): array
    {
        $images = [];
        $selectors = [
            '.product-image img',
            '.gallery img',
            '[data-product-image]',
            '.pdp-image img',
            '.product-gallery img',
            '[data-testid="product-image"] img',
            '.product-media img',
            '.carousel img',
        ];

        foreach ($selectors as $selector) {
            try {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 0) {
                    $elements->each(function (Crawler $node) use (&$images) {
                        // Try various image source attributes
                        $src = $node->attr('src')
                            ?? $node->attr('data-src')
                            ?? $node->attr('data-lazy-src')
                            ?? $node->attr('data-original');

                        if ($src && ! in_array($src, $images)) {
                            // Skip placeholder images
                            if (! str_contains($src, 'placeholder') && ! str_contains($src, 'loading')) {
                                $images[] = $src;
                            }
                        }
                    });
                }
            } catch (\Exception $e) {
                Log::debug("BMProductDetailsExtractor: Image selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * Extract product brand.
     */
    private function extractBrand(Crawler $crawler, ?string $title): ?string
    {
        // Try direct brand selectors
        $selectors = [
            '.product-brand',
            '[data-brand]',
            '.brand-name',
            '[data-testid="product-brand"]',
            '.pdp-brand',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $brand = trim($element->first()->text());
                    if (! empty($brand)) {
                        return $brand;
                    }

                    // Check data attribute
                    $dataBrand = $element->first()->attr('data-brand');
                    if ($dataBrand !== null && ! empty(trim($dataBrand))) {
                        return trim($dataBrand);
                    }
                }
            } catch (\Exception $e) {
                Log::debug("BMProductDetailsExtractor: Brand selector {$selector} failed: {$e->getMessage()}");
            }
        }

        // Try extracting from breadcrumbs
        $brand = $this->extractBrandFromBreadcrumbs($crawler);
        if ($brand !== null) {
            return $brand;
        }

        // Try extracting from title
        if ($title !== null) {
            return $this->extractBrandFromTitle($title);
        }

        return null;
    }

    /**
     * Extract brand from breadcrumbs.
     */
    private function extractBrandFromBreadcrumbs(Crawler $crawler): ?string
    {
        $breadcrumbSelectors = [
            '.breadcrumb a',
            '.breadcrumbs a',
            '[data-testid="breadcrumb"] a',
            'nav.breadcrumb a',
        ];

        foreach ($breadcrumbSelectors as $selector) {
            try {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 0) {
                    // Brand is often the second-to-last breadcrumb (before product name)
                    $breadcrumbs = $elements->each(fn (Crawler $node) => trim($node->text()));
                    $breadcrumbs = array_filter($breadcrumbs);

                    // Look for known brand patterns
                    foreach ($breadcrumbs as $crumb) {
                        if ($this->looksLikeBrand($crumb)) {
                            return $crumb;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::debug("BMProductDetailsExtractor: Breadcrumb selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract brand from product title.
     */
    private function extractBrandFromTitle(string $title): ?string
    {
        // Common pet food brands
        $knownBrands = [
            'Pedigree',
            'Whiskas',
            'Felix',
            'Iams',
            'Royal Canin',
            'Purina',
            'Harringtons',
            'Bakers',
            'Wainwright',
            'Burns',
            'James Wellbeloved',
            'Lily\'s Kitchen',
            'Forthglade',
            'Butcher\'s',
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
        ];

        foreach ($knownBrands as $brand) {
            if (stripos($title, $brand) !== false) {
                return $brand;
            }
        }

        // Try to extract the first word(s) as brand (common pattern)
        // e.g., "Pedigree Adult Dog Food" -> "Pedigree"
        $words = explode(' ', $title);
        if (count($words) > 1) {
            $firstWord = $words[0];
            // If it looks like a brand (capitalized, not a common word)
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
        // Skip common non-brand words
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
        ];

        return ! empty($text)
            && strlen($text) > 2
            && ! in_array(strtolower($text), $skipWords)
            && preg_match('/^[A-Z]/', $text); // Starts with capital letter
    }

    /**
     * Extract weight and quantity from title and page.
     *
     * @return array{weight: int|null, quantity: int|null}
     */
    private function extractWeightAndQuantity(string $title, Crawler $crawler): array
    {
        $weight = null;
        $quantity = null;

        // Try to extract from page elements first
        $weightSelectors = [
            '.product-weight',
            '[data-weight]',
            '.weight',
            '.size',
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
                Log::debug("BMProductDetailsExtractor: Weight selector {$selector} failed: {$e->getMessage()}");
            }
        }

        // Fall back to parsing from title
        if ($weight === null) {
            $weight = $this->parseWeight($title);
        }

        // Extract quantity/pack size
        if (preg_match('/(\d+)\s*(?:pack|x|pcs|pieces|count)/i', $title, $matches)) {
            $quantity = (int) $matches[1];
        }

        // Also check for "x" pattern like "12 x 400g"
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
     */
    private function extractStockStatus(Crawler $crawler): bool
    {
        // Check for out of stock indicators first
        $outOfStockSelectors = [
            '.out-of-stock',
            '[data-stock-status="out"]',
            '.sold-out',
            '.unavailable',
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
            '[data-stock-status="in"]',
            '[data-stock-status="available"]',
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

        // Check data attribute on product container
        try {
            $productElement = $crawler->filter('[data-stock-status]');
            if ($productElement->count() > 0) {
                $status = strtolower($productElement->first()->attr('data-stock-status') ?? '');
                if (in_array($status, ['out', 'outofstock', 'out-of-stock', 'unavailable'])) {
                    return false;
                }
                if (in_array($status, ['in', 'instock', 'in-stock', 'available'])) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Default to in stock
        }

        // Default to assuming in stock if we can't determine
        return true;
    }

    /**
     * Extract external product ID from URL or page.
     */
    public function extractExternalId(string $url, ?Crawler $crawler = null): ?string
    {
        // Try to extract from URL patterns
        // Pattern: /product/product-name-123456
        if (preg_match('/\/product\/[^\/]*?-(\d+)(?:\/|$|\?)/i', $url, $matches)) {
            return $matches[1];
        }

        // Pattern: /p/123456
        if (preg_match('/\/p\/(\d+)/i', $url, $matches)) {
            return $matches[1];
        }

        // Pattern: /pd/product-name or /pd/123456
        if (preg_match('/\/pd\/([a-z0-9-]+)/i', $url, $matches)) {
            return $matches[1];
        }

        // Pattern: product_id=123456 or productId=123456
        if (preg_match('/(?:product[_-]?id)=([a-z0-9-]+)/i', $url, $matches)) {
            return $matches[1];
        }

        // Try to extract from page if crawler provided
        if ($crawler !== null) {
            $idSelectors = [
                '[data-product-id]',
                '[data-sku]',
                '[data-item-id]',
                'input[name="product_id"]',
            ];

            foreach ($idSelectors as $selector) {
                try {
                    $element = $crawler->filter($selector);
                    if ($element->count() > 0) {
                        $id = $element->first()->attr('data-product-id')
                            ?? $element->first()->attr('data-sku')
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
        }

        return null;
    }

    /**
     * Extract category from breadcrumbs or URL.
     */
    private function extractCategory(Crawler $crawler, string $url): ?string
    {
        return $this->categoryExtractor->extractFromBreadcrumbs($crawler)
            ?? $this->categoryExtractor->extractFromUrl($url);
    }

    /**
     * Extract ingredients if available.
     */
    private function extractIngredients(Crawler $crawler): ?string
    {
        $selectors = [
            '.ingredients',
            '[data-ingredients]',
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
                Log::debug("BMProductDetailsExtractor: Ingredients selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Parse a price string and convert to pence.
     *
     * @param  string  $priceText  Price text like "£12.99", "12.99", "1299p"
     * @return int|null Price in pence
     */
    public function parsePriceToPence(string $priceText): ?int
    {
        // Clean the string
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

        // Handle whole number (could be pounds or pence)
        if (preg_match('/^(\d+)$/', $cleaned, $matches)) {
            $value = (int) $matches[1];

            // If the value is less than 100, assume it's pounds
            // If 100 or more, assume it's already pence
            return $value < 100 ? $value * 100 : $value;
        }

        return null;
    }
}
