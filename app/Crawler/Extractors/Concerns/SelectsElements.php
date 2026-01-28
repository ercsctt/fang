<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Concerns;

use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

trait SelectsElements
{
    /**
     * @param  array<int, string>  $selectors
     */
    protected function selectFirst(
        Crawler $crawler,
        array $selectors,
        string $context,
        ?callable $accept = null
    ): ?Crawler {
        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $candidate = $element->first();
                    if ($accept === null || $accept($candidate) === true) {
                        return $candidate;
                    }
                }
            } catch (\Exception $exception) {
                $this->logSelectorFailure($context, $selector, $exception);
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $selectors
     */
    protected function selectAll(Crawler $crawler, array $selectors, string $context): ?Crawler
    {
        foreach ($selectors as $selector) {
            try {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 0) {
                    return $elements;
                }
            } catch (\Exception $exception) {
                $this->logSelectorFailure($context, $selector, $exception);
            }
        }

        return null;
    }

    protected function logSelectorFailure(string $context, string $selector, \Exception $exception): void
    {
        $prefix = $this->getSelectorLogPrefix();

        Log::debug("{$prefix}: {$context} selector {$selector} failed: {$exception->getMessage()}");
    }

    protected function getSelectorLogPrefix(): string
    {
        return class_basename($this);
    }
}
