<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\JustForPets\JFPProductDetailsExtractor;
use App\Crawler\Extractors\JustForPets\JFPProductListingUrlExtractor;
use App\Crawler\Extractors\JustForPets\JFPProductReviewsExtractor;

class JustForPetsCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        // Register extractors for Just for Pets
        $this->addExtractor(new JFPProductListingUrlExtractor);
        $this->addExtractor(new JFPProductDetailsExtractor);
        $this->addExtractor(new JFPProductReviewsExtractor);
    }

    public function getRetailerName(): string
    {
        return 'Just for Pets';
    }

    public function getStartingUrls(): array
    {
        return [
            // Dog food categories
            'https://www.justforpetsonline.co.uk/dog/dog-food/',
            'https://www.justforpetsonline.co.uk/dog/dog-food/dry-dog-food/',
            'https://www.justforpetsonline.co.uk/dog/dog-food/wet-dog-food/',
            'https://www.justforpetsonline.co.uk/dog/dog-treats/',
            // Puppy specific
            'https://www.justforpetsonline.co.uk/dog/puppy/',
        ];
    }

    /**
     * Just for Pets specific request options.
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
     * Be respectful with request delays.
     * Specialist pet retailer - standard e-commerce, lower anti-bot risk.
     */
    public function getRequestDelay(): int
    {
        return 1500; // 1.5 seconds between requests
    }
}
