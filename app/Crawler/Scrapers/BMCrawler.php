<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\BMProductListingUrlExtractor;

class BMCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        // Register extractors for B&M
        $this->addExtractor(new BMProductListingUrlExtractor());
    }

    public function getRetailerName(): string
    {
        return 'B&M';
    }

    public function getStartingUrls(): array
    {
        return [
            // Pet food category pages
            'https://www.bmstores.co.uk/pets/dog-food',
            'https://www.bmstores.co.uk/pets/dog-treats',
            'https://www.bmstores.co.uk/pets/puppy-food',
            'https://www.bmstores.co.uk/pets',
        ];
    }

    /**
     * B&M specific request options.
     */
    protected function getRequestOptions(): array
    {
        return [
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
            ],
        ];
    }

    /**
     * Be respectful with request delays for B&M.
     */
    public function getRequestDelay(): int
    {
        return 2000; // 2 seconds between requests
    }
}
