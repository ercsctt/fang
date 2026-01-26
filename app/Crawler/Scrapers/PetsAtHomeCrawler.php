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

        $this->addExtractor(new PAHProductListingUrlExtractor);
        $this->addExtractor(new PAHProductDetailsExtractor);
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

    /**
     * Pets at Home specific request options.
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
     * Request delay for Pets at Home (2 seconds recommended).
     */
    public function getRequestDelay(): int
    {
        return 2000;
    }
}
