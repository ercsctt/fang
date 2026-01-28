<?php

declare(strict_types=1);

namespace App\Crawler\Extractors;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Concerns\ExtractsJsonLd;
use App\Crawler\Extractors\Concerns\SelectsElements;
use App\Services\ProductNormalizer;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Base class for product details extractors.
 *
 * Provides shared extraction logic for:
 * - JSON-LD parsing
 * - Title, price, description, and image extraction
 * - Brand detection from JSON-LD, selectors, or title
 * - Weight and quantity parsing
 * - Stock status detection
 */
abstract class BaseProductDetailsExtractor implements ExtractorInterface
{
    use ExtractsJsonLd;
    use SelectsElements;

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        if (! $this->shouldExtract($crawler, $html, $url)) {
            return;
        }

        $jsonLdData = $this->extractJsonLd($crawler);

        $title = $this->extractTitle($crawler, $jsonLdData);
        $price = $this->extractPrice($crawler, $jsonLdData);

        if ($title === null) {
            $this->logWarning("Could not extract title from {$url}");
        }

        if ($price === null) {
            $this->logWarning("Could not extract price from {$url}");
        }

        $externalId = $this->extractExternalId($url, $crawler, $jsonLdData);
        $weightData = $this->extractWeightAndQuantity($title ?? '', $crawler, $jsonLdData);

        yield new ProductDetails(
            title: $title ?? 'Unknown Product',
            description: $this->extractDescription($crawler, $jsonLdData),
            brand: $this->extractBrand($crawler, $jsonLdData, $title),
            pricePence: $price ?? 0,
            originalPricePence: $this->extractOriginalPrice($crawler),
            currency: $this->getCurrency(),
            weightGrams: $weightData['weight'],
            quantity: $weightData['quantity'],
            images: $this->extractImages($crawler, $jsonLdData),
            ingredients: $this->extractIngredients($crawler),
            nutritionalInfo: $this->extractNutritionalInfo($crawler),
            inStock: $this->extractStockStatus($crawler, $jsonLdData),
            stockQuantity: null,
            externalId: $externalId,
            category: $this->extractCategory($crawler, $url),
            metadata: $this->buildMetadata($crawler, $url, $externalId, $jsonLdData, $weightData),
            barcode: $this->extractBarcode($crawler, $jsonLdData),
        );

        $this->logInfo("Successfully extracted product details from {$url}");
    }

    abstract public function canHandle(string $url): bool;

    /**
     * Get the retailer slug used for ProductDetails metadata.
     */
    abstract protected function getRetailerSlug(): string;

    /**
     * @return array<int, string>
     */
    abstract protected function getTitleSelectors(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function getPriceSelectors(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function getOriginalPriceSelectors(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function getDescriptionSelectors(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function getImageSelectors(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function getBrandSelectors(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function getWeightSelectors(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function getIngredientsSelectors(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function getOutOfStockSelectors(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function getInStockSelectors(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function getAddToCartSelectors(): array;

    /**
     * Allow subclasses to perform pre-extraction checks (e.g., blocked page detection).
     */
    protected function shouldExtract(Crawler $crawler, string $html, string $url): bool
    {
        return true;
    }

    /**
     * Override to return a different currency code.
     */
    protected function getCurrency(): string
    {
        return 'GBP';
    }

    /**
     * Override to extract a retailer-specific external ID.
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    protected function extractExternalId(string $url, Crawler $crawler, array $jsonLdData): ?string
    {
        return null;
    }

    /**
     * Override to extract category from breadcrumbs or URL.
     */
    protected function extractCategory(Crawler $crawler, string $url): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $jsonLdData
     * @param  array{weight: int|null, quantity: int|null}  $weightData
     * @return array<string, mixed>
     */
    protected function buildMetadata(
        Crawler $crawler,
        string $url,
        ?string $externalId,
        array $jsonLdData,
        array $weightData
    ): array {
        return [
            'source_url' => $url,
            'extracted_at' => now()->toIso8601String(),
            'retailer' => $this->getRetailerSlug(),
        ];
    }

    /**
     * @param  array<string, mixed>  $jsonLdData
     */
    protected function extractTitle(Crawler $crawler, array $jsonLdData): ?string
    {
        if (! empty($jsonLdData['name'])) {
            return trim((string) $jsonLdData['name']);
        }

        $element = $this->selectFirst(
            $crawler,
            $this->getTitleSelectors(),
            'Title',
            fn (Crawler $node) => trim($node->text()) !== ''
        );

        return $element?->text() !== null ? trim($element->text()) : null;
    }

    /**
     * @param  array<string, mixed>  $jsonLdData
     */
    protected function extractPrice(Crawler $crawler, array $jsonLdData): ?int
    {
        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];

            if (isset($offers['@type']) || isset($offers['price'])) {
                $offers = [$offers];
            }

            foreach ($offers as $offer) {
                if (! empty($offer['price'])) {
                    return (int) round((float) $offer['price'] * 100);
                }
            }
        }

        $element = $this->selectFirst(
            $crawler,
            $this->getPriceSelectors(),
            'Price',
            fn (Crawler $node) => ($this->extractPriceFromElement($node) ?? 0) > 0
        );

        if ($element === null) {
            return null;
        }

        return $this->extractPriceFromElement($element);
    }

    protected function extractOriginalPrice(Crawler $crawler): ?int
    {
        $element = $this->selectFirst(
            $crawler,
            $this->getOriginalPriceSelectors(),
            'Original price',
            fn (Crawler $node) => ($this->extractOriginalPriceFromElement($node) ?? 0) > 0
        );

        if ($element === null) {
            return null;
        }

        return $this->extractOriginalPriceFromElement($element);
    }

    /**
     * @param  array<string, mixed>  $jsonLdData
     */
    protected function extractDescription(Crawler $crawler, array $jsonLdData): ?string
    {
        if (! empty($jsonLdData['description'])) {
            return trim((string) $jsonLdData['description']);
        }

        $element = $this->selectFirst(
            $crawler,
            $this->getDescriptionSelectors(),
            'Description',
            fn (Crawler $node) => trim($node->text()) !== ''
        );

        return $element?->text() !== null ? trim($element->text()) : null;
    }

    /**
     * @param  array<string, mixed>  $jsonLdData
     * @return array<int, string>
     */
    protected function extractImages(Crawler $crawler, array $jsonLdData): array
    {
        $images = [];

        if (! empty($jsonLdData['image'])) {
            $jsonImages = $jsonLdData['image'];
            if (is_string($jsonImages)) {
                $images[] = $this->normalizeImageUrl($jsonImages);
            } elseif (is_array($jsonImages)) {
                foreach ($jsonImages as $img) {
                    if (is_string($img)) {
                        $images[] = $this->normalizeImageUrl($img);
                    } elseif (is_array($img) && isset($img['url'])) {
                        $images[] = $this->normalizeImageUrl((string) $img['url']);
                    }
                }
            }
        }

        foreach ($this->getImageSelectors() as $selector) {
            $elements = $this->selectAll($crawler, [$selector], 'Images');
            if ($elements === null) {
                continue;
            }

            $elements->each(function (Crawler $node) use (&$images) {
                $src = $node->attr('src')
                    ?? $node->attr('data-src')
                    ?? $node->attr('data-lazy-src');

                if ($src === null) {
                    return;
                }

                $normalized = $this->normalizeImageUrl($src);
                if ($this->shouldIncludeImageUrl($normalized, $images)) {
                    $images[] = $normalized;
                }
            });
        }

        return array_values(array_unique($images));
    }

    /**
     * @param  array<string>  $existingImages
     */
    protected function shouldIncludeImageUrl(string $url, array $existingImages): bool
    {
        if (in_array($url, $existingImages, true)) {
            return false;
        }

        $lower = strtolower($url);

        if (str_contains($lower, 'placeholder')
            || str_contains($lower, 'loading')
            || str_contains($lower, 'data:')) {
            return false;
        }

        return true;
    }

    protected function normalizeImageUrl(string $url): string
    {
        return $url;
    }

    /**
     * @param  array<string, mixed>  $jsonLdData
     */
    protected function extractBrand(Crawler $crawler, array $jsonLdData, ?string $title): ?string
    {
        if (! empty($jsonLdData['brand'])) {
            $brand = $jsonLdData['brand'];
            if (is_string($brand)) {
                return $brand;
            }
            if (is_array($brand) && ! empty($brand['name'])) {
                return (string) $brand['name'];
            }
        }

        $element = $this->selectFirst(
            $crawler,
            $this->getBrandSelectors(),
            'Brand',
            fn (Crawler $node) => $this->extractBrandFromElement($node) !== null
        );

        if ($element !== null) {
            $brand = $this->extractBrandFromElement($element);
            if ($brand !== null) {
                return $brand;
            }
        }

        $breadcrumbBrand = $this->extractBrandFromBreadcrumbs($crawler);
        if ($breadcrumbBrand !== null) {
            return $breadcrumbBrand;
        }

        if ($title !== null) {
            return $this->extractBrandFromTitle($title);
        }

        return null;
    }

    protected function extractBrandFromBreadcrumbs(Crawler $crawler): ?string
    {
        return null;
    }

    protected function extractBrandFromTitle(string $title): ?string
    {
        foreach ($this->getKnownBrands() as $brand) {
            if (stripos($title, $brand) !== false) {
                return $brand;
            }
        }

        $words = array_values(array_filter(explode(' ', $title)));
        if (count($words) > 1) {
            $firstWord = $words[0];
            if ($this->looksLikeBrand($firstWord)) {
                if ($this->shouldCombineFirstTwoBrandWords()
                    && isset($words[1])
                    && $this->looksLikeBrand($words[1])) {
                    return $words[0].' '.$words[1];
                }

                return $firstWord;
            }
        }

        return null;
    }

    protected function shouldCombineFirstTwoBrandWords(): bool
    {
        return true;
    }

    protected function looksLikeBrand(string $text): bool
    {
        $skipWords = array_merge($this->getBrandSkipWords(), $this->getRetailerSpecificBrandSkipWords());

        return ! empty($text)
            && strlen($text) > 1
            && ! in_array(strtolower($text), $skipWords, true)
            && preg_match('/^[A-Z]/', $text);
    }

    /**
     * @return array<int, string>
     */
    protected function getBrandSkipWords(): array
    {
        return [
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
    }

    /**
     * @return array<int, string>
     */
    protected function getRetailerSpecificBrandSkipWords(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function getKnownBrands(): array
    {
        return array_merge(
            config('brands.known_brands', []),
            config('brands.retailer_specific.'.$this->getBrandConfigKey(), [])
        );
    }

    protected function getBrandConfigKey(): string
    {
        return $this->getRetailerSlug();
    }

    /**
     * @return array{weight: int|null, quantity: int|null}
     */
    /**
     * @param  array<string, mixed>  $jsonLdData
     * @return array{weight: int|null, quantity: int|null}
     */
    protected function extractWeightAndQuantity(string $title, Crawler $crawler, array $jsonLdData = []): array
    {
        $weight = null;

        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];

            if (isset($offers['@type']) || isset($offers['price'])) {
                $offers = [$offers];
            }

            foreach ($offers as $offer) {
                if (! empty($offer['name'])) {
                    $weight = $this->parseWeight((string) $offer['name']);
                    if ($weight !== null) {
                        break;
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

    protected function extractWeightFromSelectors(Crawler $crawler): ?int
    {
        $element = $this->selectFirst(
            $crawler,
            $this->getWeightSelectors(),
            'Weight',
            fn (Crawler $node) => $this->parseWeight($node->text()) !== null
        );

        return $element !== null ? $this->parseWeight($element->text()) : null;
    }

    protected function extractQuantityFromTitle(string $title): ?int
    {
        foreach ($this->getQuantityPatterns() as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                return (int) $matches[1];
            }
        }

        if (preg_match('/(\d+)\s*x\s*\d+/i', $title, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function getQuantityPatterns(): array
    {
        return [
            '/(\d+)\s*(?:pack|x|pcs|pieces|count)\b/i',
        ];
    }

    public function parseWeight(string $text): ?int
    {
        return app(ProductNormalizer::class)->parseWeight($text);
    }

    protected function extractStockStatus(Crawler $crawler, array $jsonLdData): bool
    {
        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];

            if (isset($offers['@type']) || isset($offers['price'])) {
                $offers = [$offers];
            }

            foreach ($offers as $offer) {
                $availability = $offer['availability'] ?? null;
                if ($availability !== null) {
                    $availability = strtolower((string) $availability);
                    if (str_contains($availability, 'instock') || str_contains($availability, 'in_stock')) {
                        return true;
                    }
                    if (str_contains($availability, 'outofstock') || str_contains($availability, 'out_of_stock')) {
                        return false;
                    }
                }
            }
        }

        if ($this->selectFirst($crawler, $this->getOutOfStockSelectors(), 'Out of stock') !== null) {
            return false;
        }

        if ($this->selectFirst($crawler, $this->getInStockSelectors(), 'In stock') !== null) {
            return true;
        }

        if ($this->selectFirst($crawler, $this->getAddToCartSelectors(), 'Add to cart') !== null) {
            return true;
        }

        return true;
    }

    protected function extractIngredients(Crawler $crawler): ?string
    {
        $element = $this->selectFirst(
            $crawler,
            $this->getIngredientsSelectors(),
            'Ingredients',
            fn (Crawler $node) => trim($node->text()) !== ''
        );

        return $element?->text() !== null ? trim($element->text()) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function extractNutritionalInfo(Crawler $crawler): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $jsonLdData
     */
    protected function extractBarcode(Crawler $crawler, array $jsonLdData): ?string
    {
        return null;
    }

    public function parsePriceToPence(string $priceText): ?int
    {
        $priceText = trim($priceText);

        if ($priceText === '') {
            return null;
        }

        if (preg_match('/^(\d+)p$/i', $priceText, $matches)) {
            return (int) $matches[1];
        }

        $cleaned = preg_replace('/[£$€\s,]/', '', $priceText);

        if ($cleaned === null || $cleaned === '') {
            return null;
        }

        if (preg_match('/^(\d+)[.,](\d{1,2})$/', $cleaned, $matches)) {
            $pounds = (int) $matches[1];
            $pence = (int) str_pad($matches[2], 2, '0');

            return ($pounds * 100) + $pence;
        }

        if (preg_match('/^(\d+)$/', $cleaned, $matches)) {
            $value = (int) $matches[1];

            return $value < 100 ? $value * 100 : $value;
        }

        return null;
    }

    protected function extractPriceFromElement(Crawler $element): ?int
    {
        $dataPrice = $element->attr('data-price')
            ?? $element->attr('content');

        if ($dataPrice !== null) {
            $price = $this->parsePriceToPence($dataPrice);
            if ($price !== null && $price > 0) {
                return $price;
            }
        }

        $dataPrice = $element->attr('data-original-price');
        if ($dataPrice !== null) {
            $price = $this->parsePriceToPence($dataPrice);
            if ($price !== null && $price > 0) {
                return $price;
            }
        }

        $priceText = trim($element->text());
        $price = $this->parsePriceToPence($priceText);

        return $price !== null && $price > 0 ? $price : null;
    }

    protected function extractOriginalPriceFromElement(Crawler $element): ?int
    {
        $dataPrice = $element->attr('data-original-price');
        if ($dataPrice !== null) {
            $price = $this->parsePriceToPence($dataPrice);
            if ($price !== null && $price > 0) {
                return $price;
            }
        }

        $priceText = trim($element->text());
        $price = $this->parsePriceToPence($priceText);

        return $price !== null && $price > 0 ? $price : null;
    }

    protected function extractBrandFromElement(Crawler $element): ?string
    {
        $text = trim($element->text());
        if (! empty($text)) {
            return $text;
        }

        $dataBrand = $element->attr('data-brand');
        if ($dataBrand !== null && ! empty(trim($dataBrand))) {
            return trim($dataBrand);
        }

        return null;
    }

    protected function logDebug(string $message): void
    {
        Log::debug($this->prefixLogMessage($message));
    }

    protected function logInfo(string $message): void
    {
        Log::info($this->prefixLogMessage($message));
    }

    protected function logWarning(string $message): void
    {
        Log::warning($this->prefixLogMessage($message));
    }

    protected function prefixLogMessage(string $message): string
    {
        $prefix = class_basename($this);

        return "{$prefix}: {$message}";
    }
}
