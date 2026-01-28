<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\Waitrose\WaitroseProductDetailsExtractor;
use App\Crawler\Extractors\Waitrose\WaitroseProductListingUrlExtractor;
use App\Crawler\Extractors\Waitrose\WaitroseProductReviewsExtractor;

class WaitroseCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        $this->addExtractor(new WaitroseProductListingUrlExtractor);
        $this->addExtractor(new WaitroseProductDetailsExtractor);
        $this->addExtractor(new WaitroseProductReviewsExtractor);
    }

    public function getRetailerName(): string
    {
        return 'Waitrose';
    }

    public function getStartingUrls(): array
    {
        return [
            // Dog food categories
            'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food',
            'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food/dry_dog_food',
            'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food/wet_dog_food',
            'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food/puppy_food',
            'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_treats',
        ];
    }

    /**
     * Waitrose specific request options.
     */
    protected function getRequestOptions(): array
    {
        return [
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ],
        ];
    }

    /**
     * Request delay for Waitrose (2 seconds to be respectful).
     */
    public function getRequestDelay(): int
    {
        return 2000;
    }
}
