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

        $this->addExtractor(WaitroseProductListingUrlExtractor::class);
        $this->addExtractor(WaitroseProductDetailsExtractor::class);
        $this->addExtractor(WaitroseProductReviewsExtractor::class);
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
}
