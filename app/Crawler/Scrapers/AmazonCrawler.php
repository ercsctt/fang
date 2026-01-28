<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\Amazon\AmazonProductDetailsExtractor;
use App\Crawler\Extractors\Amazon\AmazonProductListingUrlExtractor;
use App\Crawler\Extractors\Amazon\AmazonProductReviewsExtractor;

class AmazonCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        $this->addExtractor(AmazonProductListingUrlExtractor::class);
        $this->addExtractor(AmazonProductDetailsExtractor::class);
        $this->addExtractor(AmazonProductReviewsExtractor::class);
    }

    public function getRetailerName(): string
    {
        return 'Amazon UK';
    }

    public function getStartingUrls(): array
    {
        // Amazon UK pet food category URLs
        // Using search URLs for better coverage as Amazon's category structure is complex
        return [
            // Dog food - main categories
            'https://www.amazon.co.uk/s?k=dog+food&rh=n%3A471382031',
            'https://www.amazon.co.uk/s?k=dry+dog+food&rh=n%3A471384031',
            'https://www.amazon.co.uk/s?k=wet+dog+food&rh=n%3A471386031',
            // Dog treats
            'https://www.amazon.co.uk/s?k=dog+treats&rh=n%3A471392031',
            // Puppy food
            'https://www.amazon.co.uk/s?k=puppy+food&rh=n%3A471382031',
            // Popular brands
            'https://www.amazon.co.uk/s?k=pedigree+dog+food',
            'https://www.amazon.co.uk/s?k=royal+canin+dog+food',
            'https://www.amazon.co.uk/s?k=james+wellbeloved+dog+food',
        ];
    }
}
