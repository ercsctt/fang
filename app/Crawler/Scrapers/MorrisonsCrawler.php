<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\Morrisons\MorrisonsProductDetailsExtractor;
use App\Crawler\Extractors\Morrisons\MorrisonsProductListingUrlExtractor;
use App\Crawler\Extractors\Morrisons\MorrisonsProductReviewsExtractor;

class MorrisonsCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        $this->addExtractor(new MorrisonsProductListingUrlExtractor);
        $this->addExtractor(new MorrisonsProductDetailsExtractor);
        $this->addExtractor(new MorrisonsProductReviewsExtractor);
    }

    public function getRetailerName(): string
    {
        return 'Morrisons';
    }

    public function getStartingUrls(): array
    {
        return [
            // Dog food categories on groceries.morrisons.com
            'https://groceries.morrisons.com/browse/pet/dog',
            'https://groceries.morrisons.com/browse/pet/dog/dry-dog-food',
            'https://groceries.morrisons.com/browse/pet/dog/wet-dog-food',
            'https://groceries.morrisons.com/browse/pet/dog/dog-treats',
            'https://groceries.morrisons.com/browse/pet/dog/puppy-food',
        ];
    }

    /**
     * Morrisons specific request options.
     *
     * Morrisons Groceries may use React-based frontend and dynamic loading,
     * so we set appropriate headers to mimic browser behavior.
     */
    protected function getRequestOptions(): array
    {
        return [
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
            ],
        ];
    }

    /**
     * Request delay for Morrisons (2 seconds to be respectful).
     */
    public function getRequestDelay(): int
    {
        return 2000;
    }
}
