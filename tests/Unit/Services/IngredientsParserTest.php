<?php

declare(strict_types=1);

use App\Enums\Allergen;
use App\Services\IngredientsParser;

beforeEach(function () {
    $this->parser = new IngredientsParser;
});

describe('parseIngredientsString', function () {
    test('returns empty array for null input', function () {
        expect($this->parser->parseIngredientsString(null))->toBe([]);
    });

    test('returns empty array for empty string', function () {
        expect($this->parser->parseIngredientsString(''))->toBe([]);
        expect($this->parser->parseIngredientsString('   '))->toBe([]);
    });

    test('parses comma-separated ingredients', function () {
        $result = $this->parser->parseIngredientsString('Chicken, Rice, Carrots');

        expect($result)->toHaveCount(3)
            ->and($result[0]['name'])->toBe('Chicken')
            ->and($result[1]['name'])->toBe('Rice')
            ->and($result[2]['name'])->toBe('Carrots');
    });

    test('parses semicolon-separated ingredients', function () {
        $result = $this->parser->parseIngredientsString('Chicken; Rice; Carrots');

        expect($result)->toHaveCount(3);
    });

    test('preserves position order', function () {
        $result = $this->parser->parseIngredientsString('Chicken, Rice, Carrots');

        expect($result[0]['position'])->toBe(0)
            ->and($result[1]['position'])->toBe(1)
            ->and($result[2]['position'])->toBe(2);
    });

    test('extracts percentages from ingredients', function () {
        $result = $this->parser->parseIngredientsString('Chicken (40%), Rice (20%), Carrots');

        expect($result[0]['percentage'])->toBe('40%')
            ->and($result[1]['percentage'])->toBe('20%')
            ->and($result[2]['percentage'])->toBeNull();
    });

    test('removes Ingredients: prefix', function () {
        $result = $this->parser->parseIngredientsString('Ingredients: Chicken, Rice');

        expect($result)->toHaveCount(2)
            ->and($result[0]['name'])->toBe('Chicken');
    });

    test('removes Composition: prefix', function () {
        $result = $this->parser->parseIngredientsString('Composition: Chicken, Rice');

        expect($result)->toHaveCount(2)
            ->and($result[0]['name'])->toBe('Chicken');
    });

    test('handles complex ingredient strings with parentheses', function () {
        $result = $this->parser->parseIngredientsString(
            'Meat and animal derivatives (including 4% chicken), cereals, oils and fats'
        );

        expect($result)->toHaveCount(3)
            ->and($result[0]['name'])->toBe('Meat and animal derivatives');
    });

    test('trims whitespace from ingredients', function () {
        $result = $this->parser->parseIngredientsString('  Chicken  ,   Rice  ');

        expect($result[0]['name'])->toBe('Chicken')
            ->and($result[1]['name'])->toBe('Rice');
    });

    test('filters out empty ingredients', function () {
        $result = $this->parser->parseIngredientsString('Chicken,, Rice, , Carrots');

        expect($result)->toHaveCount(3);
    });
});

describe('detectAllergens', function () {
    test('detects chicken allergen', function () {
        $ingredients = [
            ['name' => 'Chicken', 'percentage' => null, 'position' => 0],
            ['name' => 'Rice', 'percentage' => null, 'position' => 1],
        ];

        $allergens = $this->parser->detectAllergens($ingredients);

        expect($allergens)->toContain(Allergen::Chicken)
            ->and($allergens)->toContain(Allergen::Grain);
    });

    test('detects beef allergen', function () {
        $ingredients = [
            ['name' => 'Beef', 'percentage' => null, 'position' => 0],
        ];

        $allergens = $this->parser->detectAllergens($ingredients);

        expect($allergens)->toContain(Allergen::Beef);
    });

    test('detects grain allergen from rice', function () {
        $ingredients = [
            ['name' => 'Brown Rice', 'percentage' => null, 'position' => 0],
        ];

        $allergens = $this->parser->detectAllergens($ingredients);

        expect($allergens)->toContain(Allergen::Grain);
    });

    test('detects wheat allergen', function () {
        $ingredients = [
            ['name' => 'Wheat Flour', 'percentage' => null, 'position' => 0],
        ];

        $allergens = $this->parser->detectAllergens($ingredients);

        expect($allergens)->toContain(Allergen::Wheat);
    });

    test('detects corn allergen', function () {
        $ingredients = [
            ['name' => 'Corn', 'percentage' => null, 'position' => 0],
        ];

        $allergens = $this->parser->detectAllergens($ingredients);

        expect($allergens)->toContain(Allergen::Corn);
    });

    test('detects fish allergen from salmon', function () {
        $ingredients = [
            ['name' => 'Salmon', 'percentage' => null, 'position' => 0],
        ];

        $allergens = $this->parser->detectAllergens($ingredients);

        expect($allergens)->toContain(Allergen::Fish);
    });

    test('detects dairy allergen', function () {
        $ingredients = [
            ['name' => 'Whey Protein', 'percentage' => null, 'position' => 0],
        ];

        $allergens = $this->parser->detectAllergens($ingredients);

        expect($allergens)->toContain(Allergen::Dairy);
    });

    test('detects egg allergen', function () {
        $ingredients = [
            ['name' => 'Dried Egg Product', 'percentage' => null, 'position' => 0],
        ];

        $allergens = $this->parser->detectAllergens($ingredients);

        expect($allergens)->toContain(Allergen::Egg);
    });

    test('detects soy allergen', function () {
        $ingredients = [
            ['name' => 'Soybean Oil', 'percentage' => null, 'position' => 0],
        ];

        $allergens = $this->parser->detectAllergens($ingredients);

        expect($allergens)->toContain(Allergen::Soy);
    });

    test('detects lamb allergen', function () {
        $ingredients = [
            ['name' => 'Lamb Meal', 'percentage' => null, 'position' => 0],
        ];

        $allergens = $this->parser->detectAllergens($ingredients);

        expect($allergens)->toContain(Allergen::Lamb);
    });

    test('does not duplicate allergens', function () {
        $ingredients = [
            ['name' => 'Chicken', 'percentage' => null, 'position' => 0],
            ['name' => 'Chicken Fat', 'percentage' => null, 'position' => 1],
            ['name' => 'Chicken Liver', 'percentage' => null, 'position' => 2],
        ];

        $allergens = $this->parser->detectAllergens($ingredients);
        $chickenCount = array_filter($allergens, fn ($a) => $a === Allergen::Chicken);

        expect($chickenCount)->toHaveCount(1);
    });

    test('returns empty array for allergen-free ingredients', function () {
        $ingredients = [
            ['name' => 'Sweet Potato', 'percentage' => null, 'position' => 0],
            ['name' => 'Peas', 'percentage' => null, 'position' => 1],
            ['name' => 'Carrots', 'percentage' => null, 'position' => 2],
        ];

        $allergens = $this->parser->detectAllergens($ingredients);

        expect($allergens)->toBe([]);
    });
});

describe('detectAllergensFromText', function () {
    test('detects allergens directly from ingredients text', function () {
        $allergens = $this->parser->detectAllergensFromText('Chicken, Rice, Carrots');

        expect($allergens)->toContain(Allergen::Chicken)
            ->and($allergens)->toContain(Allergen::Grain);
    });

    test('returns empty array for null input', function () {
        $allergens = $this->parser->detectAllergensFromText(null);

        expect($allergens)->toBe([]);
    });
});

describe('isGrainFree', function () {
    test('returns true when no grain allergens present', function () {
        $allergens = [Allergen::Chicken, Allergen::Fish];

        expect($this->parser->isGrainFree($allergens))->toBeTrue();
    });

    test('returns false when grain allergen present', function () {
        $allergens = [Allergen::Chicken, Allergen::Grain];

        expect($this->parser->isGrainFree($allergens))->toBeFalse();
    });

    test('returns false when wheat allergen present', function () {
        $allergens = [Allergen::Chicken, Allergen::Wheat];

        expect($this->parser->isGrainFree($allergens))->toBeFalse();
    });

    test('returns false when corn allergen present', function () {
        $allergens = [Allergen::Chicken, Allergen::Corn];

        expect($this->parser->isGrainFree($allergens))->toBeFalse();
    });

    test('returns true for empty allergens array', function () {
        expect($this->parser->isGrainFree([]))->toBeTrue();
    });
});

describe('isSingleProtein', function () {
    test('returns true with single protein', function () {
        $allergens = [Allergen::Chicken, Allergen::Grain];

        expect($this->parser->isSingleProtein($allergens))->toBeTrue();
    });

    test('returns false with multiple proteins', function () {
        $allergens = [Allergen::Chicken, Allergen::Beef];

        expect($this->parser->isSingleProtein($allergens))->toBeFalse();
    });

    test('returns true with no proteins', function () {
        $allergens = [Allergen::Grain];

        expect($this->parser->isSingleProtein($allergens))->toBeTrue();
    });

    test('returns true for empty allergens', function () {
        expect($this->parser->isSingleProtein([]))->toBeTrue();
    });
});

describe('getPrimaryProtein', function () {
    test('returns first protein found', function () {
        $allergens = [Allergen::Grain, Allergen::Chicken, Allergen::Beef];

        expect($this->parser->getPrimaryProtein($allergens))->toBe(Allergen::Chicken);
    });

    test('returns null when no protein present', function () {
        $allergens = [Allergen::Grain, Allergen::Wheat];

        expect($this->parser->getPrimaryProtein($allergens))->toBeNull();
    });

    test('returns null for empty allergens', function () {
        expect($this->parser->getPrimaryProtein([]))->toBeNull();
    });
});
