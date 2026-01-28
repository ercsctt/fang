<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\BM\BMProductListingUrlExtractor;

class BMCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        // Register extractors for B&M
        $this->addExtractor(BMProductListingUrlExtractor::class);
    }

    public function getRetailerName(): string
    {
        return 'B&M';
    }

    public function getStartingUrls(): array
    {
        return [
            'https://www.bmstores.co.uk/products/pets/dog-food-and-treats',
        ];
    }
}
