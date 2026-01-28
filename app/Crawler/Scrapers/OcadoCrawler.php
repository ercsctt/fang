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

        $this->addExtractor(OcadoProductListingUrlExtractor::class);
        $this->addExtractor(OcadoProductDetailsExtractor::class);
        $this->addExtractor(OcadoProductReviewsExtractor::class);
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
}
