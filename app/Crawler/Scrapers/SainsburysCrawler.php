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
        $this->addExtractor(SainsburysProductListingUrlExtractor::class);
        $this->addExtractor(SainsburysProductDetailsExtractor::class);
        $this->addExtractor(SainsburysProductReviewsExtractor::class);
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
}
