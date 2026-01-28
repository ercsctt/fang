<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Concerns;

use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Trait for extracting barcodes (EAN/UPC/GTIN) from product pages.
 *
 * Provides methods to extract barcodes from JSON-LD structured data,
 * meta tags, and data attributes commonly used by retailers.
 */
trait ExtractsBarcode
{
    /**
     * Extract barcode (EAN/UPC/GTIN) from the page.
     *
     * Looks for barcodes in JSON-LD structured data (gtin, gtin13, gtin8, gtin14, identifier)
     * and various meta tags commonly used by retailers.
     *
     * @param  array<string, mixed>  $jsonLdData
     */
    protected function extractBarcode(Crawler $crawler, array $jsonLdData = []): ?string
    {
        // Try JSON-LD GTIN fields first (most reliable)
        $gtinFields = ['gtin13', 'gtin', 'gtin8', 'gtin14', 'gtin12', 'ean', 'upc'];

        foreach ($gtinFields as $field) {
            if (! empty($jsonLdData[$field])) {
                $barcode = $this->normalizeExtractedBarcode($jsonLdData[$field]);
                if ($barcode !== null) {
                    return $barcode;
                }
            }
        }

        // Try identifier field (some sites use this)
        if (! empty($jsonLdData['identifier'])) {
            $barcode = $this->extractBarcodeFromIdentifier($jsonLdData['identifier']);
            if ($barcode !== null) {
                return $barcode;
            }
        }

        // Try productID field
        if (! empty($jsonLdData['productID'])) {
            $barcode = $this->normalizeExtractedBarcode($jsonLdData['productID']);
            if ($barcode !== null) {
                return $barcode;
            }
        }

        // Try offers for GTIN
        $barcode = $this->extractBarcodeFromOffers($jsonLdData['offers'] ?? null, $gtinFields);
        if ($barcode !== null) {
            return $barcode;
        }

        // Try meta tags
        $barcode = $this->extractBarcodeFromMetaTags($crawler);
        if ($barcode !== null) {
            return $barcode;
        }

        return null;
    }

    /**
     * Extract barcode from identifier field in JSON-LD.
     *
     * @param  mixed  $identifiers
     */
    protected function extractBarcodeFromIdentifier($identifiers): ?string
    {
        if (! is_array($identifiers)) {
            $identifiers = [$identifiers];
        }

        foreach ($identifiers as $identifier) {
            if (is_array($identifier)) {
                $value = $identifier['value'] ?? $identifier['@value'] ?? null;
                $type = $identifier['@type'] ?? $identifier['type'] ?? null;

                // Accept if type mentions EAN or GTIN, or if no type specified
                $typeMatches = $type === null
                    || str_contains(strtolower((string) $type), 'ean')
                    || str_contains(strtolower((string) $type), 'gtin')
                    || str_contains(strtolower((string) $type), 'upc');

                if ($value !== null && $typeMatches) {
                    $barcode = $this->normalizeExtractedBarcode($value);
                    if ($barcode !== null) {
                        return $barcode;
                    }
                }
            } else {
                $barcode = $this->normalizeExtractedBarcode($identifier);
                if ($barcode !== null) {
                    return $barcode;
                }
            }
        }

        return null;
    }

    /**
     * Extract barcode from offers array in JSON-LD.
     *
     * @param  mixed  $offers
     * @param  array<string>  $gtinFields
     */
    protected function extractBarcodeFromOffers($offers, array $gtinFields): ?string
    {
        if (empty($offers)) {
            return null;
        }

        // Normalize to array
        if (isset($offers['@type']) || isset($offers['price'])) {
            $offers = [$offers];
        }

        foreach ($offers as $offer) {
            if (! is_array($offer)) {
                continue;
            }

            foreach ($gtinFields as $field) {
                if (! empty($offer[$field])) {
                    $barcode = $this->normalizeExtractedBarcode($offer[$field]);
                    if ($barcode !== null) {
                        return $barcode;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract barcode from meta tags and data attributes.
     */
    protected function extractBarcodeFromMetaTags(Crawler $crawler): ?string
    {
        $metaSelectors = [
            'meta[property="product:ean"]',
            'meta[property="product:gtin"]',
            'meta[property="product:upc"]',
            'meta[property="og:gtin"]',
            'meta[property="og:ean"]',
            'meta[name="ean"]',
            'meta[name="gtin"]',
            'meta[name="upc"]',
            'meta[itemprop="gtin"]',
            'meta[itemprop="gtin13"]',
            'meta[itemprop="gtin8"]',
            'meta[itemprop="gtin14"]',
            'meta[itemprop="ean"]',
            '[data-barcode]',
            '[data-ean]',
            '[data-gtin]',
            '[data-upc]',
        ];

        foreach ($metaSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $content = $element->first()->attr('content')
                        ?? $element->first()->attr('data-barcode')
                        ?? $element->first()->attr('data-ean')
                        ?? $element->first()->attr('data-gtin')
                        ?? $element->first()->attr('data-upc');

                    if ($content !== null) {
                        $barcode = $this->normalizeExtractedBarcode($content);
                        if ($barcode !== null) {
                            return $barcode;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::debug("ExtractsBarcode: Barcode selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Normalize an extracted barcode value.
     *
     * Strips non-numeric characters and validates length.
     *
     * @param  mixed  $value
     */
    protected function normalizeExtractedBarcode($value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $cleaned = preg_replace('/[^0-9]/', '', (string) $value);

        if ($cleaned === null || $cleaned === '') {
            return null;
        }

        // Valid barcode lengths: EAN-8 (8), UPC-A (12), EAN-13 (13), GTIN-14 (14)
        $validLengths = [8, 12, 13, 14];

        if (! in_array(strlen($cleaned), $validLengths)) {
            return null;
        }

        return $cleaned;
    }
}
