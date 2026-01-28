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
        $this->addExtractor(JFPProductListingUrlExtractor::class);
        $this->addExtractor(JFPProductDetailsExtractor::class);
        $this->addExtractor(JFPProductReviewsExtractor::class);
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
}
