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

        $this->addExtractor(new AsdaProductListingUrlExtractor);
        $this->addExtractor(new AsdaProductDetailsExtractor);
        $this->addExtractor(new AsdaProductReviewsExtractor);
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

    /**
     * Asda specific request options.
     *
     * Asda Groceries may use dynamic loading (AJAX/infinite scroll),
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
     * Request delay for Asda (2 seconds to be respectful).
     */
    public function getRequestDelay(): int
    {
        return 2000;
    }
}
