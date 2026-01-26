<?php

declare(strict_types=1);

use App\Services\ProductNormalizer;

beforeEach(function () {
    $this->normalizer = new ProductNormalizer;
});

describe('normalizeTitle', function () {
    test('converts title to lowercase', function () {
        $result = $this->normalizer->normalizeTitle('PEDIGREE Chicken Dog Food');

        expect($result)->toBe('pedigree chicken dog food');
    });

    test('removes weight specifications', function () {
        $result = $this->normalizer->normalizeTitle('Pedigree Chicken 2kg');

        expect($result)->not->toContain('2kg')
            ->and($result)->toBe('pedigree chicken');
    });

    test('removes various weight formats', function () {
        $results = [
            $this->normalizer->normalizeTitle('Food 2.5kg'),
            $this->normalizer->normalizeTitle('Food 400g'),
            $this->normalizer->normalizeTitle('Food 15 kg'),
            $this->normalizer->normalizeTitle('Food 5lb'),
        ];

        foreach ($results as $result) {
            expect($result)->toBe('food');
        }
    });

    test('removes pack size information', function () {
        $result = $this->normalizer->normalizeTitle('Wet Food 12x400g');

        expect($result)->not->toContain('12x')
            ->and($result)->toBe('wet food');
    });

    test('removes special characters', function () {
        $result = $this->normalizer->normalizeTitle("Lily's Kitchen - Premium!");

        expect($result)->not->toContain("'")
            ->and($result)->not->toContain('-')
            ->and($result)->not->toContain('!');
    });

    test('normalizes whitespace', function () {
        $result = $this->normalizer->normalizeTitle('Pedigree    Chicken   Food');

        expect($result)->toBe('pedigree chicken food');
    });

    test('removes common redundant phrases', function () {
        $result = $this->normalizer->normalizeTitle('Pedigree Complete Adult Natural Chicken with Rice Premium');

        expect($result)->not->toContain('complete')
            ->and($result)->not->toContain('adult')
            ->and($result)->not->toContain('natural')
            ->and($result)->not->toContain('premium');
    });
});

describe('normalizeBrand', function () {
    test('returns null for null input', function () {
        expect($this->normalizer->normalizeBrand(null))->toBeNull();
    });

    test('returns null for empty string', function () {
        expect($this->normalizer->normalizeBrand(''))->toBeNull();
    });

    test('capitalizes brand names', function () {
        expect($this->normalizer->normalizeBrand('pedigree'))->toBe('Pedigree');
    });

    test('normalizes known brand aliases', function () {
        expect($this->normalizer->normalizeBrand("lily's kitchen"))->toBe("Lily's Kitchen")
            ->and($this->normalizer->normalizeBrand('lilys kitchen'))->toBe("Lily's Kitchen")
            ->and($this->normalizer->normalizeBrand('james wellbeloved'))->toBe('James Wellbeloved')
            ->and($this->normalizer->normalizeBrand('royal canin'))->toBe('Royal Canin');
    });

    test('trims whitespace', function () {
        expect($this->normalizer->normalizeBrand('  Pedigree  '))->toBe('Pedigree');
    });
});

describe('extractWeightFromTitle', function () {
    test('extracts weight in grams', function () {
        expect($this->normalizer->extractWeightFromTitle('Food 400g'))->toBe(400);
    });

    test('extracts weight in kilograms and converts to grams', function () {
        expect($this->normalizer->extractWeightFromTitle('Food 2kg'))->toBe(2000)
            ->and($this->normalizer->extractWeightFromTitle('Food 2.5kg'))->toBe(2500);
    });

    test('extracts weight with spaces', function () {
        expect($this->normalizer->extractWeightFromTitle('Food 15 kg'))->toBe(15000);
    });

    test('extracts weight in pounds', function () {
        $result = $this->normalizer->extractWeightFromTitle('Food 5lb');

        expect($result)->toBe(2268); // 5 * 453.592 rounded
    });

    test('returns null when no weight found', function () {
        expect($this->normalizer->extractWeightFromTitle('Pedigree Chicken'))->toBeNull();
    });
});

describe('extractQuantityFromTitle', function () {
    test('extracts pack quantity with x', function () {
        expect($this->normalizer->extractQuantityFromTitle('Wet Food 12x400g'))->toBe(12);
    });

    test('extracts pack quantity with spaces', function () {
        expect($this->normalizer->extractQuantityFromTitle('Wet Food 6 x 400g'))->toBe(6);
    });

    test('extracts pack quantity with word pack', function () {
        expect($this->normalizer->extractQuantityFromTitle('Treats 20 pack'))->toBe(20);
    });

    test('returns null when no quantity found', function () {
        expect($this->normalizer->extractQuantityFromTitle('Single Food Bag 2kg'))->toBeNull();
    });
});

describe('normalizeWeight', function () {
    test('returns null for null value', function () {
        expect($this->normalizer->normalizeWeight(null))->toBeNull();
    });

    test('returns grams when unit is g', function () {
        expect($this->normalizer->normalizeWeight(400, 'g'))->toBe(400);
    });

    test('converts kg to grams', function () {
        expect($this->normalizer->normalizeWeight(2, 'kg'))->toBe(2000)
            ->and($this->normalizer->normalizeWeight(2.5, 'kg'))->toBe(2500);
    });

    test('converts lb to grams', function () {
        expect($this->normalizer->normalizeWeight(1, 'lb'))->toBe(454); // 453.592 rounded
    });

    test('defaults to grams for unknown unit', function () {
        expect($this->normalizer->normalizeWeight(500, 'unknown'))->toBe(500);
    });
});

describe('weightsMatch', function () {
    test('returns false when either weight is null', function () {
        expect($this->normalizer->weightsMatch(null, 1000))->toBeFalse()
            ->and($this->normalizer->weightsMatch(1000, null))->toBeFalse()
            ->and($this->normalizer->weightsMatch(null, null))->toBeFalse();
    });

    test('returns true for identical weights', function () {
        expect($this->normalizer->weightsMatch(2000, 2000))->toBeTrue();
    });

    test('returns true for weights within tolerance', function () {
        // Default tolerance is 5%
        // 2000 +/- 5% = 1900 to 2100
        expect($this->normalizer->weightsMatch(2000, 2050))->toBeTrue()
            ->and($this->normalizer->weightsMatch(2000, 1950))->toBeTrue();
    });

    test('returns false for weights outside tolerance', function () {
        // 2000 +/- 5% = 1900 to 2100, so 2200 should fail
        expect($this->normalizer->weightsMatch(2000, 2200))->toBeFalse();
    });

    test('respects custom tolerance', function () {
        // With 10% tolerance, 2000 can match up to 2200
        expect($this->normalizer->weightsMatch(2000, 2200, 0.10))->toBeTrue()
            ->and($this->normalizer->weightsMatch(2000, 2300, 0.10))->toBeFalse();
    });
});

describe('brandsMatch', function () {
    test('returns false when either brand is null', function () {
        expect($this->normalizer->brandsMatch(null, 'Pedigree'))->toBeFalse()
            ->and($this->normalizer->brandsMatch('Pedigree', null))->toBeFalse();
    });

    test('matches identical brands', function () {
        expect($this->normalizer->brandsMatch('Pedigree', 'Pedigree'))->toBeTrue();
    });

    test('matches brands case-insensitively', function () {
        expect($this->normalizer->brandsMatch('PEDIGREE', 'pedigree'))->toBeTrue()
            ->and($this->normalizer->brandsMatch('pedigree', 'Pedigree'))->toBeTrue();
    });

    test('matches brand aliases', function () {
        expect($this->normalizer->brandsMatch("lily's kitchen", "Lily's Kitchen"))->toBeTrue()
            ->and($this->normalizer->brandsMatch('lilys kitchen', "Lily's Kitchen"))->toBeTrue();
    });
});

describe('calculateTitleSimilarity', function () {
    test('returns 100 for identical normalized titles', function () {
        expect($this->normalizer->calculateTitleSimilarity(
            'Pedigree Chicken Dog Food',
            'pedigree chicken dog food'
        ))->toBe(100.0);
    });

    test('returns 0 for empty strings', function () {
        expect($this->normalizer->calculateTitleSimilarity('', 'test'))->toBe(0.0)
            ->and($this->normalizer->calculateTitleSimilarity('test', ''))->toBe(0.0);
    });

    test('returns high score for similar titles', function () {
        $similarity = $this->normalizer->calculateTitleSimilarity(
            'Pedigree Chicken Dog Food 2kg',
            'Pedigree Chicken Dogfood 2kg'
        );

        expect($similarity)->toBeGreaterThan(80.0);
    });

    test('returns lower score for dissimilar titles', function () {
        $similarity = $this->normalizer->calculateTitleSimilarity(
            'Pedigree Chicken',
            'Royal Canin Beef'
        );

        expect($similarity)->toBeLessThan(50.0);
    });
});
