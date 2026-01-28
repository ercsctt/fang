<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Amazon;

use App\Crawler\Extractors\BaseProductDetailsExtractor;
use Symfony\Component\DomCrawler\Crawler;

class AmazonProductDetailsExtractor extends BaseProductDetailsExtractor
{
    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'amazon.co.uk')) {
            return preg_match('/\/dp\/[A-Z0-9]{10}(?:\/|$|\?)/i', $url) === 1
                || preg_match('/\/gp\/product\/[A-Z0-9]{10}(?:\/|$|\?)/i', $url) === 1;
        }

        return false;
    }

    protected function getRetailerSlug(): string
    {
        return 'amazon-uk';
    }

    protected function getBrandConfigKey(): string
    {
        return 'amazon';
    }

    protected function shouldExtract(Crawler $crawler, string $html, string $url): bool
    {
        if ($this->isBlockedPage($crawler, $html)) {
            $this->logWarning("Blocked/CAPTCHA page detected at {$url}");

            return false;
        }

        return true;
    }

    protected function getTitleSelectors(): array
    {
        return [
            '#productTitle',
            '#title span',
            'h1.a-size-large',
            'h1[data-automation-id="title"]',
            '#titleSection #title',
            'h1',
        ];
    }

    protected function getPriceSelectors(): array
    {
        return [
            '.priceToPay .a-offscreen',
            '.priceToPay span.a-price-whole',
            '#corePrice_feature_div .a-price .a-offscreen',
            '#corePriceDisplay_desktop_feature_div .a-price .a-offscreen',
            '#priceblock_ourprice',
            '#priceblock_dealprice',
            '#priceblock_saleprice',
            '.a-price .a-offscreen',
            'span[data-a-color="price"] .a-offscreen',
            '.a-price-whole',
        ];
    }

    protected function getOriginalPriceSelectors(): array
    {
        return [
            '.basisPrice .a-offscreen',
            'span[data-a-strike="true"] .a-offscreen',
            '.a-text-strike .a-offscreen',
            '#listPrice',
            '#priceblock_listprice',
            '.a-price[data-a-strike="true"] .a-offscreen',
            '#rrp .a-offscreen',
            '.rrp .a-offscreen',
        ];
    }

    protected function getDescriptionSelectors(): array
    {
        return [
            '#productDescription p',
            '#productDescription',
            '#feature-bullets ul',
            '#feature-bullets',
            '#aplus_feature_div',
            '#aplus3p_feature_div',
            '[data-feature-name="productDescription"]',
        ];
    }

    protected function getImageSelectors(): array
    {
        return [
            '#imgTagWrapperId img',
            '#landingImage',
            '#main-image',
            '.imgTagWrapper img',
            '#imageBlock img',
        ];
    }

    protected function getBrandSelectors(): array
    {
        return [
            '#bylineInfo',
            '.po-brand .a-span9',
            '#brand',
            '#bylineInfo_feature_div a',
            '#brandBylineWrapper',
            '#brandBylineWrapper a',
            '#bylineInfo_feature_div span',
            '.prodDetSectionEntry:contains("Brand") + td',
            'tr:contains("Brand") td',
            '#detailBullets_feature_div li:contains("Brand") span.a-text-bold + span',
        ];
    }

    /**
     * @param  array<string, mixed>  $jsonLdData
     */
    protected function extractBrand(Crawler $crawler, array $jsonLdData, ?string $title): ?string
    {
        $brand = parent::extractBrand($crawler, $jsonLdData, $title);

        if ($brand === null) {
            return null;
        }

        $brand = preg_replace('/^visit the\s+/i', '', $brand) ?? $brand;
        $brand = preg_replace('/\s+store$/i', '', $brand) ?? $brand;
        $brand = preg_replace('/^brand:\s*/i', '', $brand) ?? $brand;
        $brand = preg_replace('/^by\s+/i', '', $brand) ?? $brand;

        $brand = trim($brand);

        return $brand !== '' ? $brand : null;
    }

    protected function getWeightSelectors(): array
    {
        return [];
    }

    protected function getIngredientsSelectors(): array
    {
        return [
            '#important-information',
            '#aplus_feature_div',
            '#productDescription',
            '.ingredients',
            '[data-feature-name="ingredients"]',
        ];
    }

    protected function getOutOfStockSelectors(): array
    {
        return [];
    }

    protected function getInStockSelectors(): array
    {
        return [];
    }

    protected function getAddToCartSelectors(): array
    {
        return [];
    }

    protected function getQuantityPatterns(): array
    {
        return [
            '/(\d+)\s*(?:pack|count|pcs|pieces|tins|pouches|sachets|bags)\b/i',
        ];
    }

    protected function normalizeImageUrl(string $url): string
    {
        return $this->upgradeImageUrl($url);
    }

    protected function extractExternalId(string $url, Crawler $crawler, array $jsonLdData): ?string
    {
        return $this->extractAsin($url, $crawler);
    }

    protected function extractWeightAndQuantity(string $title, Crawler $crawler, array $jsonLdData = []): array
    {
        $weight = null;
        $quantity = null;

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

        try {
            $sizeElement = $crawler->filter('#variation_size_name .selection, #twister_feature_div .selection');
            if ($sizeElement->count() > 0) {
                $weight = $this->parseWeight($sizeElement->first()->text());
            }
        } catch (\Exception $e) {
            // Continue
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

    protected function extractStockStatus(Crawler $crawler, array $jsonLdData): bool
    {
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

                    if (str_contains($text, 'out of stock')
                        || str_contains($text, 'currently unavailable')
                        || str_contains($text, 'not available')) {
                        return false;
                    }

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

        try {
            $addToCart = $crawler->filter('#add-to-cart-button, #addToCart');
            if ($addToCart->count() > 0) {
                return true;
            }
        } catch (\Exception $e) {
            // Continue
        }

        return true;
    }

    protected function extractImages(Crawler $crawler, array $jsonLdData): array
    {
        $images = parent::extractImages($crawler, $jsonLdData);

        try {
            $scripts = $crawler->filter('script');
            foreach ($scripts as $script) {
                $content = $script->textContent;
                if (str_contains($content, 'colorImages') || str_contains($content, 'imageGalleryData')) {
                    if (preg_match_all('/"hiRes"\s*:\s*"([^"]+)"/', $content, $matches)) {
                        foreach ($matches[1] as $url) {
                            $normalized = $this->normalizeImageUrl($url);
                            if ($this->shouldIncludeImageUrl($normalized, $images) && str_contains($normalized, 'amazon')) {
                                $images[] = $normalized;
                            }
                        }
                    }
                    if (preg_match_all('/"large"\s*:\s*"([^"]+)"/', $content, $matches)) {
                        foreach ($matches[1] as $url) {
                            $normalized = $this->normalizeImageUrl($url);
                            if ($this->shouldIncludeImageUrl($normalized, $images) && str_contains($normalized, 'amazon')) {
                                $images[] = $normalized;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logDebug("Image script parsing failed: {$e->getMessage()}");
        }

        foreach ($this->getImageSelectors() as $selector) {
            try {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 0) {
                    $elements->each(function (Crawler $node) use (&$images) {
                        $src = $node->attr('data-old-hires')
                            ?? $node->attr('data-a-dynamic-image')
                            ?? $node->attr('src');

                        if ($src === null) {
                            return;
                        }

                        if (str_starts_with($src, '{')) {
                            $imageData = json_decode($src, true);
                            if (is_array($imageData)) {
                                foreach (array_keys($imageData) as $url) {
                                    $normalized = $this->normalizeImageUrl((string) $url);
                                    if ($this->shouldIncludeImageUrl($normalized, $images)) {
                                        $images[] = $normalized;
                                    }
                                }
                            }

                            return;
                        }

                        $normalized = $this->normalizeImageUrl($src);
                        if ($this->shouldIncludeImageUrl($normalized, $images) && str_contains($normalized, 'amazon')) {
                            $images[] = $normalized;
                        }
                    });
                }
            } catch (\Exception $e) {
                $this->logDebug("Image selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return array_values(array_unique($images));
    }

    protected function extractIngredients(Crawler $crawler): ?string
    {
        foreach ($this->getIngredientsSelectors() as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $element->first()->text();
                    if (preg_match('/(?:ingredients|composition)[:\s]*([^.]+(?:\.[^.]+){0,5})/i', $text, $matches)) {
                        return trim($matches[1]);
                    }
                }
            } catch (\Exception $e) {
                $this->logDebug("Ingredients selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    protected function extractCategory(Crawler $crawler, string $url): ?string
    {
        try {
            $breadcrumb = $crawler->filter('#wayfinding-breadcrumbs_container ul.a-unordered-list a, #nav-subnav .nav-a-content');
            if ($breadcrumb->count() > 0) {
                $crumbs = $breadcrumb->each(fn (Crawler $node) => trim($node->text()));
                $crumbs = array_values(array_filter($crumbs));

                if (count($crumbs) >= 2) {
                    $categoryIndex = count($crumbs) - 1;

                    return $crumbs[$categoryIndex];
                }
            }
        } catch (\Exception $e) {
            $this->logDebug("Category extraction failed: {$e->getMessage()}");
        }

        return null;
    }

    protected function buildMetadata(
        Crawler $crawler,
        string $url,
        ?string $externalId,
        array $jsonLdData,
        array $weightData
    ): array {
        return array_merge(parent::buildMetadata($crawler, $url, $externalId, $jsonLdData, $weightData), [
            'asin' => $externalId,
            'rating_value' => $this->extractRating($crawler),
            'review_count' => $this->extractReviewCount($crawler),
            'subscribe_save_price' => $this->extractSubscribeAndSavePrice($crawler),
            'deal_badge' => $this->extractDealBadge($crawler),
            'prime_eligible' => $this->isPrimeEligible($crawler),
        ]);
    }

    /**
     * Check if the page is blocked or shows a CAPTCHA.
     */
    private function isBlockedPage(Crawler $crawler, string $html): bool
    {
        if (str_contains($html, 'captcha') || str_contains($html, 'robot check')) {
            return true;
        }

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
     * Upgrade Amazon image URL to larger size.
     */
    private function upgradeImageUrl(string $url): string
    {
        return preg_replace('/\._[A-Z]{2}\d+_\./', '._SL1500_.', $url) ?? $url;
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
                    $price = $this->parsePriceToPence($priceText);
                    if ($price !== null) {
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
     * Extract deal badge text.
     */
    private function extractDealBadge(Crawler $crawler): ?string
    {
        $selectors = [
            '.dealBadge',
            '.savingsPercentage',
            '.dealBadgeText',
            '.priceBlockDealPriceString',
            '.saving-price',
            '.savingsPercentage',
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
        if (preg_match('/\/dp\/([A-Z0-9]{10})(?:\/|$|\?)/i', $url, $matches)) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/\/gp\/product\/([A-Z0-9]{10})(?:\/|$|\?)/i', $url, $matches)) {
            return strtoupper($matches[1]);
        }

        if ($crawler !== null) {
            try {
                $asinElement = $crawler->filter('#ASIN, [name="ASIN"]');
                if ($asinElement->count() > 0) {
                    $asin = $asinElement->first()->attr('value');
                    if ($asin !== null && ! empty(trim($asin))) {
                        return strtoupper(trim($asin));
                    }
                }
            } catch (\Exception $e) {
                // Continue
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
            $countElement = $crawler->filter('#acrCustomerReviewText, [data-hook="total-review-count"], #reviewCount');
            if ($countElement->count() > 0) {
                $text = $countElement->first()->text();
                if (preg_match('/(\d+[\d,]*)/', $text, $matches)) {
                    return (int) str_replace(',', '', $matches[1]);
                }
            }
        } catch (\Exception $e) {
            // Continue
        }

        return null;
    }
}
