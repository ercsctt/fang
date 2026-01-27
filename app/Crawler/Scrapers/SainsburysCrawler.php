<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\Sainsburys\SainsburysProductDetailsExtractor;
use App\Crawler\Extractors\Sainsburys\SainsburysProductListingUrlExtractor;
use App\Crawler\Extractors\Sainsburys\SainsburysProductReviewsExtractor;

class SainsburysCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        // Register extractors for Sainsbury's
        $this->addExtractor(new SainsburysProductListingUrlExtractor);
        $this->addExtractor(new SainsburysProductDetailsExtractor);
        $this->addExtractor(new SainsburysProductReviewsExtractor);
    }

    public function getRetailerName(): string
    {
        return "Sainsbury's";
    }

    public function getStartingUrls(): array
    {
        return [
            // Dog food categories via GOL-UI
            'https://www.sainsburys.co.uk/gol-ui/groceries/pets/dog-food-and-treats/dog-food',
            'https://www.sainsburys.co.uk/gol-ui/groceries/pets/dog-food-and-treats/dry-dog-food',
            'https://www.sainsburys.co.uk/gol-ui/groceries/pets/dog-food-and-treats/wet-dog-food',
            'https://www.sainsburys.co.uk/gol-ui/groceries/pets/dog-food-and-treats/dog-treats',
            // Puppy specific
            'https://www.sainsburys.co.uk/gol-ui/groceries/pets/dog-food-and-treats/puppy-food',
        ];
    }

    /**
     * Sainsbury's specific request options.
     */
    protected function getRequestOptions(): array
    {
        return [
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                // Sainsbury's may require cookies/postcode for availability
                // Using a default UK postcode area for consistency
                'Cookie' => 'JSESSIONID=; AWSALB=',
            ],
        ];
    }

    /**
     * Be respectful with request delays.
     * Sainsbury's is a major supermarket - standard anti-bot measures expected.
     */
    public function getRequestDelay(): int
    {
        return 2000; // 2 seconds between requests
    }
}
