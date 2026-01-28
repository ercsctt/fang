<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\Extractors\Ocado\OcadoProductDetailsExtractor;
use App\Crawler\Extractors\Ocado\OcadoProductListingUrlExtractor;
use App\Crawler\Extractors\Ocado\OcadoProductReviewsExtractor;

class OcadoCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);

        $this->addExtractor(new OcadoProductListingUrlExtractor);
        $this->addExtractor(new OcadoProductDetailsExtractor);
        $this->addExtractor(new OcadoProductReviewsExtractor);
    }

    public function getRetailerName(): string
    {
        return 'Ocado';
    }

    public function getStartingUrls(): array
    {
        return [
            // Dog food categories
            'https://www.ocado.com/browse/pets-20974/dog-111797/dog-food-111800',
            'https://www.ocado.com/browse/pets-20974/dog-111797/dog-food-111800/dry-dog-food-111801',
            'https://www.ocado.com/browse/pets-20974/dog-111797/dog-food-111800/wet-dog-food-111802',
            'https://www.ocado.com/browse/pets-20974/dog-111797/dog-treats-rewards-111806',
            'https://www.ocado.com/browse/pets-20974/dog-111797/puppy-111811/puppy-food-111812',
        ];
    }

    /**
     * Ocado-specific request options.
     * Ocado has strong anti-bot measures, so we need browser-like headers.
     */
    protected function getRequestOptions(): array
    {
        return [
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Sec-Ch-Ua' => '"Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
                'Sec-Ch-Ua-Mobile' => '?0',
                'Sec-Ch-Ua-Platform' => '"Windows"',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
            ],
        ];
    }

    public function getRetailerSlug(): string
    {
        return 'ocado';
    }
}
