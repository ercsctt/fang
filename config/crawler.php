<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Request Settings
    |--------------------------------------------------------------------------
    |
    | Default configuration for all crawlers. Individual retailers can override
    | these settings in the retailers array below.
    |
    */

    'default_delay' => 1000, // Default delay in milliseconds between requests

    'default_headers' => [
        'Accept-Language' => 'en-GB,en;q=0.9',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retailer-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for each retailer's crawler. Keys should match the
    | getRetailerSlug() method on the crawler class.
    |
    */

    'retailers' => [
        'amazon' => [
            'request_delay' => 3000, // Amazon has aggressive anti-bot detection
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
        ],

        'asda' => [
            'request_delay' => 2000,
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
            ],
        ],

        'bm' => [
            'request_delay' => 2000,
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
            ],
        ],

        'justforpets' => [
            'request_delay' => 1500,
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ],
        ],

        'morrisons' => [
            'request_delay' => 2000,
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
            ],
        ],

        'ocado' => [
            'request_delay' => 1000, // Uses default delay
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
        ],

        'petsathome' => [
            'request_delay' => 2000,
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ],
        ],

        'sainsburys' => [
            'request_delay' => 2000,
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Cookie' => 'JSESSIONID=; AWSALB=',
            ],
        ],

        'tesco' => [
            'request_delay' => 2000,
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ],
        ],

        'waitrose' => [
            'request_delay' => 2000,
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ],
        ],

        'zooplus' => [
            'request_delay' => 2000,
            'headers' => [
                'Accept-Language' => 'en-GB,en;q=0.9',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Category Detection Patterns
    |--------------------------------------------------------------------------
    |
    | Patterns used by CategoryExtractor to identify product categories from
    | URLs. These patterns are applied in order when extracting categories.
    |
    */

    'category_patterns' => [
        // Specific product categories (most specific first)
        'dog-food' => [
            '/dog-food/i',
            '/puppy-food/i',
        ],
        'dog-treats' => [
            '/dog-treats/i',
            '/puppy-treats/i',
        ],
        'cat-food' => [
            '/cat-food/i',
            '/kitten-food/i',
        ],
        'cat-treats' => [
            '/cat-treats/i',
            '/kitten-treats/i',
        ],
        'dog-accessories' => [
            '/dog-accessories/i',
            '/puppy-accessories/i',
        ],
        'cat-accessories' => [
            '/cat-accessories/i',
            '/kitten-accessories/i',
        ],
        // General animal categories (fallback)
        'dog' => [
            '/\/dog(?:\/|$|\?|-)/i',
            '/\/puppy(?:\/|$|\?|-)/i',
        ],
        'cat' => [
            '/\/cat(?:\/|$|\?|-)/i',
            '/\/kitten(?:\/|$|\?|-)/i',
        ],
        'pets' => [
            '/\/pets?(?:\/|$|\?|-)/i',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Category Extraction Filters
    |--------------------------------------------------------------------------
    |
    | Generic terms to exclude when extracting categories from breadcrumbs
    | or URL paths, as they don't provide meaningful categorization.
    |
    */

    'category_filters' => [
        'home',
        'groceries',
        'shop',
        'all',
        'pets',
        '',
    ],
];
