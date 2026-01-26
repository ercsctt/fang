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

        $this->addExtractor(new AmazonProductListingUrlExtractor);
        $this->addExtractor(new AmazonProductDetailsExtractor);
        $this->addExtractor(new AmazonProductReviewsExtractor);
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

    /**
     * Amazon UK specific request options.
     *
     * Amazon requires specific headers to avoid blocks.
     * Note: Using BrightData Web Unlocker is recommended.
     */
    protected function getRequestOptions(): array
    {
        return [
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Cache-Control' => 'max-age=0',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
            ],
        ];
    }

    /**
     * Request delay for Amazon UK.
     *
     * Amazon has aggressive anti-bot detection, so we use a longer delay.
     * 3 seconds between requests to avoid triggering rate limits.
     */
    public function getRequestDelay(): int
    {
        return 3000;
    }
}
