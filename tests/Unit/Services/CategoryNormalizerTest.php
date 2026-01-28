<?php

declare(strict_types=1);

use App\Enums\CanonicalCategory;
use App\Services\CategoryNormalizer;

describe('CategoryNormalizer', function () {
    beforeEach(function () {
        $this->normalizer = new CategoryNormalizer;
    });

    describe('normalize', function () {
        it('normalizes dry food categories', function () {
            expect($this->normalizer->normalize('Dry Food'))->toBe(CanonicalCategory::DryFood);
            expect($this->normalizer->normalize('kibble'))->toBe(CanonicalCategory::DryFood);
            expect($this->normalizer->normalize('Complete Food'))->toBe(CanonicalCategory::DryFood);
            expect($this->normalizer->normalize('ADULT DRY'))->toBe(CanonicalCategory::DryFood);
        });

        it('normalizes wet food categories', function () {
            expect($this->normalizer->normalize('Wet Food'))->toBe(CanonicalCategory::WetFood);
            expect($this->normalizer->normalize('canned food'))->toBe(CanonicalCategory::WetFood);
            expect($this->normalizer->normalize('Pouches'))->toBe(CanonicalCategory::WetFood);
            expect($this->normalizer->normalize('Tins'))->toBe(CanonicalCategory::WetFood);
            expect($this->normalizer->normalize('chunks in gravy'))->toBe(CanonicalCategory::WetFood);
        });

        it('normalizes treat categories', function () {
            expect($this->normalizer->normalize('Treats'))->toBe(CanonicalCategory::Treats);
            expect($this->normalizer->normalize('snacks'))->toBe(CanonicalCategory::Treats);
            expect($this->normalizer->normalize('Training Treats'))->toBe(CanonicalCategory::Treats);
            expect($this->normalizer->normalize('Chews'))->toBe(CanonicalCategory::Treats);
        });

        it('normalizes dental categories', function () {
            expect($this->normalizer->normalize('Dental'))->toBe(CanonicalCategory::Dental);
            expect($this->normalizer->normalize('dentastix'))->toBe(CanonicalCategory::Dental);
            expect($this->normalizer->normalize('Dental Chews'))->toBe(CanonicalCategory::Dental);
            expect($this->normalizer->normalize('oral care'))->toBe(CanonicalCategory::Dental);
        });

        it('normalizes puppy food categories', function () {
            expect($this->normalizer->normalize('Puppy'))->toBe(CanonicalCategory::PuppyFood);
            expect($this->normalizer->normalize('puppy food'))->toBe(CanonicalCategory::PuppyFood);
            expect($this->normalizer->normalize('Junior'))->toBe(CanonicalCategory::PuppyFood);
        });

        it('normalizes senior food categories', function () {
            expect($this->normalizer->normalize('Senior'))->toBe(CanonicalCategory::SeniorFood);
            expect($this->normalizer->normalize('senior food'))->toBe(CanonicalCategory::SeniorFood);
            expect($this->normalizer->normalize('Mature'))->toBe(CanonicalCategory::SeniorFood);
            expect($this->normalizer->normalize('7+'))->toBe(CanonicalCategory::SeniorFood);
        });

        it('returns Other for unknown categories', function () {
            expect($this->normalizer->normalize('Unknown Category'))->toBe(CanonicalCategory::Other);
            expect($this->normalizer->normalize('Something Random'))->toBe(CanonicalCategory::Other);
        });

        it('returns Other for null or empty categories', function () {
            expect($this->normalizer->normalize(null))->toBe(CanonicalCategory::Other);
            expect($this->normalizer->normalize(''))->toBe(CanonicalCategory::Other);
            expect($this->normalizer->normalize('   '))->toBe(CanonicalCategory::Other);
        });

        it('handles partial matches in longer strings', function () {
            expect($this->normalizer->normalize('Dog Treats & Snacks'))->toBe(CanonicalCategory::Treats);
            expect($this->normalizer->normalize('Premium Dry Food for Dogs'))->toBe(CanonicalCategory::DryFood);
            expect($this->normalizer->normalize('Wet Food Pouches'))->toBe(CanonicalCategory::WetFood);
        });

        it('is case insensitive', function () {
            expect($this->normalizer->normalize('DRY FOOD'))->toBe(CanonicalCategory::DryFood);
            expect($this->normalizer->normalize('wet food'))->toBe(CanonicalCategory::WetFood);
            expect($this->normalizer->normalize('TrEaTs'))->toBe(CanonicalCategory::Treats);
        });
    });

    describe('normalizeWithContext', function () {
        it('prefers category over title when category is conclusive', function () {
            expect($this->normalizer->normalizeWithContext('Dry Food', 'Some Wet Product'))->toBe(CanonicalCategory::DryFood);
        });

        it('uses title when category is not conclusive', function () {
            expect($this->normalizer->normalizeWithContext('Food', 'Puppy Complete Nutrition'))->toBe(CanonicalCategory::PuppyFood);
            expect($this->normalizer->normalizeWithContext(null, 'Senior Dog Food'))->toBe(CanonicalCategory::SeniorFood);
        });

        it('returns Other when both category and title are not conclusive', function () {
            expect($this->normalizer->normalizeWithContext('Food', 'Generic Product'))->toBe(CanonicalCategory::Other);
        });
    });

    describe('addMapping', function () {
        it('allows adding custom category mappings', function () {
            $this->normalizer->addMapping('special treats', CanonicalCategory::Treats);

            expect($this->normalizer->normalize('special treats'))->toBe(CanonicalCategory::Treats);
        });

        it('handles case insensitivity for custom mappings', function () {
            $this->normalizer->addMapping('Custom Category', CanonicalCategory::DryFood);

            expect($this->normalizer->normalize('custom category'))->toBe(CanonicalCategory::DryFood);
            expect($this->normalizer->normalize('CUSTOM CATEGORY'))->toBe(CanonicalCategory::DryFood);
        });
    });

    describe('getMappings', function () {
        it('returns all category mappings', function () {
            $mappings = $this->normalizer->getMappings();

            expect($mappings)->toBeArray();
            expect($mappings)->toHaveKey('dry food');
            expect($mappings)->toHaveKey('wet food');
            expect($mappings['dry food'])->toBe(CanonicalCategory::DryFood);
        });
    });
});
