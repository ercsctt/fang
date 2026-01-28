<?php

declare(strict_types=1);

namespace App\Enums;

enum CanonicalCategory: string
{
    case DryFood = 'dry_food';
    case WetFood = 'wet_food';
    case Treats = 'treats';
    case Dental = 'dental';
    case PuppyFood = 'puppy_food';
    case SeniorFood = 'senior_food';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DryFood => 'Dry Food',
            self::WetFood => 'Wet Food',
            self::Treats => 'Treats',
            self::Dental => 'Dental',
            self::PuppyFood => 'Puppy Food',
            self::SeniorFood => 'Senior Food',
            self::Other => 'Other',
        };
    }
}
