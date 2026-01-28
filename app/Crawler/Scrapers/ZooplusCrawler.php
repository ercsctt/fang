<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\Zooplus\ZooplusProductDetailsExtractor;
use App\Crawler\Extractors\Zooplus\ZooplusProductListingUrlExtractor;
use App\Crawler\Extractors\Zooplus\ZooplusProductReviewsExtractor;

class ZooplusCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        $this->addExtractor(ZooplusProductListingUrlExtractor::class);
        $this->addExtractor(ZooplusProductDetailsExtractor::class);
        $this->addExtractor(ZooplusProductReviewsExtractor::class);
    }

    public function getRetailerName(): string
    {
        return 'Zooplus UK';
    }

    public function getStartingUrls(): array
    {
        return [
            // Dog food categories
            'https://www.zooplus.co.uk/shop/dogs/dry_dog_food',
            'https://www.zooplus.co.uk/shop/dogs/wet_dog_food',
            'https://www.zooplus.co.uk/shop/dogs/puppy_food',
            'https://www.zooplus.co.uk/shop/dogs/dog_food_senior',
            'https://www.zooplus.co.uk/shop/dogs/barf_raw_dog_food',

            // Dog treats and chews
            'https://www.zooplus.co.uk/shop/dogs/dog_treats_chews',
            'https://www.zooplus.co.uk/shop/dogs/dog_treats_chews/dog_chews',
            'https://www.zooplus.co.uk/shop/dogs/dog_treats_chews/dog_biscuits_treats',
            'https://www.zooplus.co.uk/shop/dogs/dog_treats_chews/training_treats',
            'https://www.zooplus.co.uk/shop/dogs/dog_treats_chews/dental_treats',
        ];
    }
}
