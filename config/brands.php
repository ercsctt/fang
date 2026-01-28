<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Known Pet Food Brands
    |--------------------------------------------------------------------------
    |
    | This array contains the core pet food brands recognized across all
    | retailers. These brands are used for brand extraction fallback when
    | explicit brand data is not available from the retailer's website.
    |
    */

    'known_brands' => [
        'Acana',
        'Adventuros',
        'Akela',
        'Animonda',
        'Applaws',
        'Arden Grange',
        'Autarky',
        'AVA',
        'Bakers',
        'Barking Heads',
        'Beta',
        'Blue Buffalo',
        'Bozita',
        'Brit',
        'Burgess',
        'Burns',
        'Butcher\'s',
        "Butcher's",
        'Canagan',
        'Canidae',
        'Canine Choice',
        'Carnilove',
        'Cesar',
        'Chappie',
        'Concept for Life',
        'Cosma',
        'Country Hunter',
        'Crave',
        'CSJ',
        'Dentalife',
        'Dentastix',
        'Denzel',
        'Dreamies',
        'Eden',
        'Edgard & Cooper',
        'Encore',
        'Eukanuba',
        'Felix',
        'Feringa',
        'Fish4Dogs',
        'Forthglade',
        'Friskies',
        'Frolic',
        'Good Boy',
        'Gourmet',
        'Greenwoods',
        'Guru',
        'Happy Cat',
        'Happy Dog',
        'Harringtons',
        'HiLife',
        'Hill\'s',
        "Hill's",
        'Hills',
        'Iams',
        'James Wellbeloved',
        'Josera',
        'Lily\'s Kitchen',
        "Lily's Kitchen",
        'Lovebites',
        'Lukullus',
        'Markus Muhle',
        'Millies Wolfheart',
        'Natural Instinct',
        'Nature\'s Menu',
        "Nature's Menu",
        'Natures Deli',
        'Natures Menu',
        'Natures Variety',
        'Naturo',
        'Nutriment',
        'Orijen',
        'Pedigree',
        'Pero',
        'Pero Gold',
        'Pooch & Mutt',
        'Pro Plan',
        'ProPlan',
        'Purina',
        'Purizon',
        'Rocco',
        'Royal Canin',
        'Sanabelle',
        'Scrumbles',
        'Sheba',
        'Skinners',
        'Smilla',
        'Soopa',
        'Symply',
        'Tails.com',
        'Taste of the Wild',
        'Thrive',
        'Tribal',
        'Vet\'s Kitchen',
        "Vet's Kitchen",
        'Wagg',
        'Wainwright\'s',
        "Wainwright's",
        'Wainwrights',
        'Webbox',
        'Wellness',
        'Whiskas',
        'Wild Freedom',
        'Winalot',
        'Wolf of Wilderness',
        'Wolfsblut',
        'Wolfworthy',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retailer-Specific Brands
    |--------------------------------------------------------------------------
    |
    | This array contains own-brand labels and exclusive brands for specific
    | retailers. These are used in addition to the known_brands array for
    | retailer-specific brand detection.
    |
    */

    'retailer_specific' => [
        'amazon' => [
            'Amazon Basics',
            'Lifelong',
            'Solimo',
            'Wag',
        ],

        'asda' => [
            'ASDA',
            'Extra Special',
            'Smart Price',
        ],

        'morrisons' => [
            'Morrisons',
            'Savers',
            'The Best',
        ],

        'sainsburys' => [
            'Sainsbury\'s',
            "Sainsbury's",
            'by Sainsbury\'s',
            "by Sainsbury's",
            'Basics',
            'Taste the Difference',
        ],

        'waitrose' => [
            'essential Waitrose',
            'Waitrose',
        ],

        'zooplus' => [
            'zooplus',
        ],

        // JustForPets, PetsAtHome, Ocado, Tesco don't have explicit own brands in the data
        'justforpets' => [],
        'petsathome' => [],
        'ocado' => [],
        'tesco' => [],
    ],

];
