<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\Tesco\TescoProductDetailsExtractor;
use App\Crawler\Extractors\Tesco\TescoProductListingUrlExtractor;

class TescoCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        $this->addExtractor(new TescoProductListingUrlExtractor);
        $this->addExtractor(new TescoProductDetailsExtractor);
    }

    public function getRetailerName(): string
    {
        return 'Tesco';
    }

    public function getStartingUrls(): array
    {
        return [
            // Dog food categories
            'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/all',
            'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/dry-dog-food',
            'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/wet-dog-food',
            'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/dog-treats',
            'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/puppy-food',
        ];
    }

    /**
     * Tesco specific request options.
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
     * Request delay for Tesco (2 seconds to be respectful).
     */
    public function getRequestDelay(): int
    {
        return 2000;
    }
}
