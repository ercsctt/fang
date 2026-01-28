<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\Morrisons\MorrisonsProductDetailsExtractor;
use App\Crawler\Extractors\Morrisons\MorrisonsProductListingUrlExtractor;
use App\Crawler\Extractors\Morrisons\MorrisonsProductReviewsExtractor;

class MorrisonsCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        $this->addExtractor(MorrisonsProductListingUrlExtractor::class);
        $this->addExtractor(MorrisonsProductDetailsExtractor::class);
        $this->addExtractor(MorrisonsProductReviewsExtractor::class);
    }

    public function getRetailerName(): string
    {
        return 'Morrisons';
    }

    public function getStartingUrls(): array
    {
        return [
            // Dog food categories on groceries.morrisons.com
            'https://groceries.morrisons.com/browse/pet/dog',
            'https://groceries.morrisons.com/browse/pet/dog/dry-dog-food',
            'https://groceries.morrisons.com/browse/pet/dog/wet-dog-food',
            'https://groceries.morrisons.com/browse/pet/dog/dog-treats',
            'https://groceries.morrisons.com/browse/pet/dog/puppy-food',
        ];
    }
}
