<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Concerns;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Trait providing common pagination extraction functionality for ProductListingUrlExtractors.
 */
trait ExtractsPagination
{
    /**
     * Common CSS selectors for pagination "Next" links.
     *
     * @var array<string>
     */
    protected array $nextPageSelectors = [
        'a[rel="next"]',
        'a[aria-label*="Next"]',
        'a[aria-label*="next"]',
        'a[aria-label="Go to next page"]',
        '.pagination-next a',
        '.pagination__next a',
        'a.next',
        'a.pagination-link--next',
        '[class*="pagination"] a[class*="next"]',
        'nav[aria-label*="pagination"] a[class*="next"]',
    ];

    /**
     * Find the next page URL from pagination links.
     */
    protected function findNextPageLink(Crawler $crawler, string $currentUrl): ?string
    {
        // Try each common "Next" link selector
        foreach ($this->nextPageSelectors as $selector) {
            try {
                $nextLink = $crawler->filter($selector);
                if ($nextLink->count() > 0) {
                    $href = $nextLink->first()->attr('href');
                    if ($href !== null && ! $this->isInvalidPaginationLink($href)) {
                        return $this->normalizePageUrl($href, $currentUrl);
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Try finding next page by number
        return $this->findNextPageByNumber($crawler, $currentUrl);
    }

    /**
     * Find next page link by looking for page number links.
     */
    protected function findNextPageByNumber(Crawler $crawler, string $currentUrl): ?string
    {
        $currentPage = $this->extractCurrentPageNumber($currentUrl);
        $nextPage = $currentPage + 1;

        // Common pagination container selectors
        $paginationSelectors = [
            '.pagination a',
            '[class*="pagination"] a',
            'nav[aria-label*="pagination"] a',
            '.pager a',
            '[class*="pager"] a',
        ];

        foreach ($paginationSelectors as $selector) {
            try {
                $links = $crawler->filter($selector);
                foreach ($links as $node) {
                    $linkCrawler = new Crawler($node);
                    $text = trim($linkCrawler->text(''));

                    // Check if this link's text is the next page number
                    if ($text === (string) $nextPage) {
                        $href = $linkCrawler->attr('href');
                        if ($href !== null && ! $this->isInvalidPaginationLink($href)) {
                            return $this->normalizePageUrl($href, $currentUrl);
                        }
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Check if a pagination link is invalid (javascript void, etc).
     */
    protected function isInvalidPaginationLink(string $href): bool
    {
        $invalidPatterns = [
            'javascript:',
            '#',
            'void(0)',
        ];

        foreach ($invalidPatterns as $pattern) {
            if (str_contains(strtolower($href), $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract current page number from URL.
     * Override in specific extractors for custom URL patterns.
     */
    protected function extractCurrentPageNumber(string $url): int
    {
        // Common patterns: ?page=N, &page=N, /page/N/, /p/N/
        if (preg_match('/[?&]page=(\d+)/i', $url, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/\/page\/(\d+)/i', $url, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/\/p\/(\d+)/i', $url, $matches)) {
            return (int) $matches[1];
        }

        // Some sites use start/offset params
        // e.g., ?start=24 with 24 items per page = page 2
        if (preg_match('/[?&]start=(\d+)/i', $url, $matches)) {
            $start = (int) $matches[1];

            // Assume 24 items per page (common default)
            return (int) floor($start / 24) + 1;
        }

        return 1;
    }

    /**
     * Normalize a pagination URL to absolute form.
     * Should be implemented by each extractor to use their normalizeUrl method.
     */
    abstract protected function normalizePageUrl(string $href, string $baseUrl): string;
}
