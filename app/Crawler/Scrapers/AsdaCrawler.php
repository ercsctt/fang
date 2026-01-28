<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\Asda\AsdaProductDetailsExtractor;
use App\Crawler\Extractors\Asda\AsdaProductListingUrlExtractor;
use App\Crawler\Extractors\Asda\AsdaProductReviewsExtractor;

class AsdaCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        $this->addExtractor(AsdaProductListingUrlExtractor::class);
        $this->addExtractor(AsdaProductDetailsExtractor::class);
        $this->addExtractor(AsdaProductReviewsExtractor::class);
    }

    public function getRetailerName(): string
    {
        return 'Asda';
    }

    public function getStartingUrls(): array
    {
        return [
            // Dog food main categories on groceries.asda.com
            'https://groceries.asda.com/aisle/pet-shop/dog/dog-food',
            'https://groceries.asda.com/aisle/pet-shop/dog/dog-food/dry-dog-food',
            'https://groceries.asda.com/aisle/pet-shop/dog/dog-food/wet-dog-food',
            'https://groceries.asda.com/aisle/pet-shop/dog/dog-food/puppy-food',
            // Dog treats
            'https://groceries.asda.com/aisle/pet-shop/dog/dog-treats',
            'https://groceries.asda.com/aisle/pet-shop/dog/dog-treats/dog-chews',
            'https://groceries.asda.com/aisle/pet-shop/dog/dog-treats/dog-biscuits',
        ];
    }
}
