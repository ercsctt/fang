<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\PetsAtHome\PAHProductDetailsExtractor;
use App\Crawler\Extractors\PetsAtHome\PAHProductListingUrlExtractor;

class PetsAtHomeCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        $this->addExtractor(PAHProductListingUrlExtractor::class);
        $this->addExtractor(PAHProductDetailsExtractor::class);
    }

    public function getRetailerName(): string
    {
        return 'Pets at Home';
    }

    public function getStartingUrls(): array
    {
        return [
            // Dog food categories
            'https://www.petsathome.com/shop/en/pets/dog/dog-food',
            'https://www.petsathome.com/shop/en/pets/dog/dog-food/dry-dog-food',
            'https://www.petsathome.com/shop/en/pets/dog/dog-food/wet-dog-food',
            'https://www.petsathome.com/shop/en/pets/dog/dog-treats',
            'https://www.petsathome.com/shop/en/pets/dog/puppy/puppy-food',
            'https://www.petsathome.com/shop/en/pets/dog/puppy/puppy-treats',
        ];
    }
}
