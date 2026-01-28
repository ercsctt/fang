<?php

declare(strict_types=1);

namespace App\Enums;

enum Allergen: string
{
    case Grain = 'grain';
    case Wheat = 'wheat';
    case Corn = 'corn';
    case Soy = 'soy';
    case Chicken = 'chicken';
    case Beef = 'beef';
    case Pork = 'pork';
    case Fish = 'fish';
    case Dairy = 'dairy';
    case Egg = 'egg';
    case Lamb = 'lamb';

    public function label(): string
    {
        return match ($this) {
            self::Grain => 'Grain',
            self::Wheat => 'Wheat',
            self::Corn => 'Corn',
            self::Soy => 'Soy',
            self::Chicken => 'Chicken',
            self::Beef => 'Beef',
            self::Pork => 'Pork',
            self::Fish => 'Fish',
            self::Dairy => 'Dairy',
            self::Egg => 'Egg',
            self::Lamb => 'Lamb',
        };
    }

    /**
     * Get keywords that indicate this allergen is present in an ingredient.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::Grain => ['grain', 'rice', 'oat', 'barley', 'millet', 'sorghum', 'cereal'],
            self::Wheat => ['wheat', 'gluten', 'flour'],
            self::Corn => ['corn', 'maize', 'cornmeal', 'corn starch'],
            self::Soy => ['soy', 'soya', 'soybean', 'soja'],
            self::Chicken => ['chicken', 'poultry'],
            self::Beef => ['beef', 'cattle', 'ox', 'bovine'],
            self::Pork => ['pork', 'pig', 'swine', 'ham', 'bacon'],
            self::Fish => ['fish', 'salmon', 'tuna', 'cod', 'herring', 'anchovy', 'sardine', 'trout', 'mackerel', 'whitefish'],
            self::Dairy => ['dairy', 'milk', 'cheese', 'lactose', 'whey', 'casein', 'cream', 'butter', 'yogurt'],
            self::Egg => ['egg', 'albumen'],
            self::Lamb => ['lamb', 'mutton', 'sheep', 'ovine'],
        };
    }

    /**
     * Get all allergen keywords mapped to their allergen.
     *
     * @return array<string, self>
     */
    public static function keywordMap(): array
    {
        $map = [];
        foreach (self::cases() as $allergen) {
            foreach ($allergen->keywords() as $keyword) {
                $map[$keyword] = $allergen;
            }
        }

        return $map;
    }
}
