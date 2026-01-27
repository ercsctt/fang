<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Retailer;
use Illuminate\Database\Seeder;

class RetailerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $retailers = [
            [
                'name' => 'B&M',
                'slug' => 'bm',
                'base_url' => 'https://www.bmstores.co.uk',
                'crawler_class' => 'App\\Crawler\\Scrapers\\BMCrawler',
                'is_active' => true,
                'rate_limit_ms' => 1000,
            ],
            [
                'name' => 'Pets at Home',
                'slug' => 'pets-at-home',
                'base_url' => 'https://www.petsathome.com',
                'crawler_class' => 'App\\Crawler\\Scrapers\\PetsAtHomeCrawler',
                'is_active' => true,
                'rate_limit_ms' => 2000,
            ],
            [
                'name' => 'Tesco',
                'slug' => 'tesco',
                'base_url' => 'https://www.tesco.com',
                'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
                'is_active' => true,
                'rate_limit_ms' => 2000,
            ],
            [
                'name' => 'Asda',
                'slug' => 'asda',
                'base_url' => 'https://groceries.asda.com',
                'crawler_class' => 'App\\Crawler\\Scrapers\\AsdaCrawler',
                'is_active' => true,
                'rate_limit_ms' => 2000,
            ],
            [
                'name' => 'Amazon UK',
                'slug' => 'amazon-uk',
                'base_url' => 'https://www.amazon.co.uk',
                'crawler_class' => 'App\\Crawler\\Scrapers\\AmazonCrawler',
                'is_active' => true,
                'rate_limit_ms' => 3000,
            ],
            [
                'name' => "Sainsbury's",
                'slug' => 'sainsburys',
                'base_url' => 'https://www.sainsburys.co.uk',
                'crawler_class' => 'App\\Crawler\\Scrapers\\SainsburysCrawler',
                'is_active' => true,
                'rate_limit_ms' => 2000,
            ],
            [
                'name' => 'Morrisons',
                'slug' => 'morrisons',
                'base_url' => 'https://groceries.morrisons.com',
                'crawler_class' => 'App\\Crawler\\Scrapers\\MorrisonsCrawler',
                'is_active' => true,
                'rate_limit_ms' => 2000,
            ],
            [
                'name' => 'Just for Pets',
                'slug' => 'just-for-pets',
                'base_url' => 'https://www.justforpetsonline.co.uk',
                'crawler_class' => 'App\\Crawler\\Scrapers\\JustForPetsCrawler',
                'is_active' => true,
                'rate_limit_ms' => 1500,
            ],
        ];

        foreach ($retailers as $retailer) {
            Retailer::query()->updateOrCreate(
                ['slug' => $retailer['slug']],
                $retailer
            );
        }
    }
}
