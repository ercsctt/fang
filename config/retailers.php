<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Retailer Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for each supported retailer including crawling settings,
    | pagination limits, and request delays.
    |
    */

    'amazon-uk' => [
        'name' => 'Amazon UK',
        'domain' => 'amazon.co.uk',
        'max_pages' => 50,
        'request_delay_ms' => 3000,
    ],

    'pets-at-home' => [
        'name' => 'Pets at Home',
        'domain' => 'petsathome.com',
        'max_pages' => 100,
        'request_delay_ms' => 2000,
    ],

    'tesco' => [
        'name' => 'Tesco',
        'domain' => 'tesco.com',
        'max_pages' => 50,
        'request_delay_ms' => 2000,
    ],

    'asda' => [
        'name' => 'Asda',
        'domain' => 'asda.com',
        'max_pages' => 50,
        'request_delay_ms' => 2000,
    ],

    'sainsburys' => [
        'name' => 'Sainsburys',
        'domain' => 'sainsburys.co.uk',
        'max_pages' => 50,
        'request_delay_ms' => 2000,
    ],

    'morrisons' => [
        'name' => 'Morrisons',
        'domain' => 'morrisons.com',
        'max_pages' => 50,
        'request_delay_ms' => 2000,
    ],

    'bm' => [
        'name' => 'B&M',
        'domain' => 'bmstores.co.uk',
        'max_pages' => 30,
        'request_delay_ms' => 2000,
    ],

    'just-for-pets' => [
        'name' => 'Just For Pets',
        'domain' => 'justforpets.com',
        'max_pages' => 30,
        'request_delay_ms' => 2000,
    ],

    'waitrose' => [
        'name' => 'Waitrose',
        'domain' => 'waitrose.com',
        'max_pages' => 50,
        'request_delay_ms' => 2000,
    ],

    'ocado' => [
        'name' => 'Ocado',
        'domain' => 'ocado.com',
        'max_pages' => 50,
        'request_delay_ms' => 3000, // Higher delay - Ocado has strong anti-bot measures
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Settings
    |--------------------------------------------------------------------------
    |
    | Default configuration applied when retailer-specific settings aren't defined.
    |
    */

    'defaults' => [
        'max_pages' => 20,
        'request_delay_ms' => 2000,
    ],
];
