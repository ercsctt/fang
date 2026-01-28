<?php

declare(strict_types=1);

use App\Enums\Allergen;
use App\Models\Ingredient;
use App\Models\ProductListing;
use App\Services\IngredientsParser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Ingredient Model', function () {
    test('can create ingredient with factory', function () {
        $ingredient = Ingredient::factory()->create();

        expect($ingredient)->toBeInstanceOf(Ingredient::class)
            ->and($ingredient->name)->toBeString()
            ->and($ingredient->normalized_name)->toBeString();
    });

    test('auto-generates normalized name on create', function () {
        $ingredient = Ingredient::create([
            'name' => 'Chicken Meal',
            'category' => 'protein',
            'allergens' => [Allergen::Chicken->value],
        ]);

        expect($ingredient->normalized_name)->toBe('chicken_meal');
    });

    test('normalizes names consistently', function () {
        expect(Ingredient::normalizeName('Chicken Meal'))->toBe('chicken_meal')
            ->and(Ingredient::normalizeName('CHICKEN MEAL'))->toBe('chicken_meal')
            ->and(Ingredient::normalizeName('  Chicken  Meal  '))->toBe('chicken_meal')
            ->and(Ingredient::normalizeName("Lily's Kitchen"))->toBe('lilys_kitchen');
    });

    test('can check if ingredient has allergen', function () {
        $ingredient = Ingredient::factory()->create([
            'name' => 'Chicken',
            'allergens' => [Allergen::Chicken->value],
        ]);

        expect($ingredient->hasAllergen(Allergen::Chicken))->toBeTrue()
            ->and($ingredient->hasAllergen(Allergen::Beef))->toBeFalse();
    });

    test('can get allergen enums', function () {
        $ingredient = Ingredient::factory()->create([
            'name' => 'Corn',
            'allergens' => [Allergen::Corn->value, Allergen::Grain->value],
        ]);

        $allergenEnums = $ingredient->getAllergenEnums();

        expect($allergenEnums)->toHaveCount(2)
            ->and($allergenEnums)->toContain(Allergen::Corn)
            ->and($allergenEnums)->toContain(Allergen::Grain);
    });

    test('findOrCreateByName creates new ingredient', function () {
        $ingredient = Ingredient::findOrCreateByName('Sweet Potato', 'carbohydrate', []);

        expect($ingredient)->toBeInstanceOf(Ingredient::class)
            ->and($ingredient->name)->toBe('Sweet Potato')
            ->and($ingredient->normalized_name)->toBe('sweet_potato')
            ->and($ingredient->category)->toBe('carbohydrate');
    });

    test('findOrCreateByName returns existing ingredient', function () {
        $existing = Ingredient::create([
            'name' => 'Chicken',
            'normalized_name' => 'chicken',
            'category' => 'protein',
            'allergens' => [Allergen::Chicken->value],
        ]);

        $found = Ingredient::findOrCreateByName('CHICKEN');

        expect($found->id)->toBe($existing->id);
    });
});

describe('ProductListing ingredients relationship', function () {
    test('can attach ingredients to product listing', function () {
        $productListing = ProductListing::factory()->create([
            'ingredients' => 'Chicken, Rice, Carrots',
        ]);

        $chicken = Ingredient::factory()->create(['name' => 'Chicken']);
        $rice = Ingredient::factory()->create(['name' => 'Rice']);

        $productListing->ingredientRelation()->attach($chicken->id, ['position' => 0]);
        $productListing->ingredientRelation()->attach($rice->id, ['position' => 1]);

        expect($productListing->ingredientRelation)->toHaveCount(2)
            ->and($productListing->ingredientRelation->first()->name)->toBe('Chicken')
            ->and($productListing->ingredientRelation->first()->pivot->position)->toBe(0);
    });

    test('ingredients ordered by position', function () {
        $productListing = ProductListing::factory()->create();

        $third = Ingredient::factory()->create(['name' => 'Carrots']);
        $first = Ingredient::factory()->create(['name' => 'Chicken']);
        $second = Ingredient::factory()->create(['name' => 'Rice']);

        $productListing->ingredientRelation()->attach($third->id, ['position' => 2]);
        $productListing->ingredientRelation()->attach($first->id, ['position' => 0]);
        $productListing->ingredientRelation()->attach($second->id, ['position' => 1]);

        $ingredients = $productListing->fresh()->ingredientRelation;

        expect($ingredients[0]->name)->toBe('Chicken')
            ->and($ingredients[1]->name)->toBe('Rice')
            ->and($ingredients[2]->name)->toBe('Carrots');
    });

    test('can store percentage in pivot', function () {
        $productListing = ProductListing::factory()->create();
        $chicken = Ingredient::factory()->create(['name' => 'Chicken']);

        $productListing->ingredientRelation()->attach($chicken->id, [
            'position' => 0,
            'percentage' => '40%',
        ]);

        expect($productListing->ingredientRelation->first()->pivot->percentage)->toBe('40%');
    });
});

describe('IngredientsParser parseAndSync', function () {
    test('parses and syncs ingredients to database', function () {
        $productListing = ProductListing::factory()->create([
            'ingredients' => 'Chicken (40%), Brown Rice (20%), Carrots, Sweet Potato',
        ]);

        $parser = app(IngredientsParser::class);
        $result = $parser->parseAndSync($productListing);

        expect($result['ingredients'])->toHaveCount(4)
            ->and($result['allergens'])->toContain(Allergen::Chicken)
            ->and($result['allergens'])->toContain(Allergen::Grain);

        $productListing->refresh();
        expect($productListing->ingredientRelation)->toHaveCount(4)
            ->and($productListing->ingredientRelation->first()->name)->toBe('Chicken')
            ->and($productListing->ingredientRelation->first()->pivot->percentage)->toBe('40%');
    });

    test('creates ingredient records in database', function () {
        $productListing = ProductListing::factory()->create([
            'ingredients' => 'Salmon, Peas, Flaxseed',
        ]);

        $parser = app(IngredientsParser::class);
        $parser->parseAndSync($productListing);

        expect(Ingredient::where('normalized_name', 'salmon')->exists())->toBeTrue()
            ->and(Ingredient::where('normalized_name', 'peas')->exists())->toBeTrue()
            ->and(Ingredient::where('normalized_name', 'flaxseed')->exists())->toBeTrue();
    });

    test('assigns categories to ingredients', function () {
        $productListing = ProductListing::factory()->create([
            'ingredients' => 'Chicken, Rice, Carrots, Apple',
        ]);

        $parser = app(IngredientsParser::class);
        $parser->parseAndSync($productListing);

        expect(Ingredient::where('normalized_name', 'chicken')->first()->category)->toBe('protein')
            ->and(Ingredient::where('normalized_name', 'rice')->first()->category)->toBe('carbohydrate')
            ->and(Ingredient::where('normalized_name', 'carrots')->first()->category)->toBe('vegetable')
            ->and(Ingredient::where('normalized_name', 'apple')->first()->category)->toBe('fruit');
    });

    test('assigns allergens to ingredients', function () {
        $productListing = ProductListing::factory()->create([
            'ingredients' => 'Chicken, Wheat Flour, Egg',
        ]);

        $parser = app(IngredientsParser::class);
        $parser->parseAndSync($productListing);

        $chicken = Ingredient::where('normalized_name', 'chicken')->first();
        $wheat = Ingredient::where('normalized_name', 'wheat_flour')->first();
        $egg = Ingredient::where('normalized_name', 'egg')->first();

        expect($chicken->allergens)->toContain(Allergen::Chicken->value)
            ->and($wheat->allergens)->toContain(Allergen::Wheat->value)
            ->and($egg->allergens)->toContain(Allergen::Egg->value);
    });
});

describe('ProductListing allergen scopes', function () {
    beforeEach(function () {
        $this->parser = app(IngredientsParser::class);
    });

    test('grainFree scope filters grain-free products', function () {
        $grainFree = ProductListing::factory()->create([
            'ingredients' => 'Chicken, Sweet Potato, Peas',
        ]);
        $withGrain = ProductListing::factory()->create([
            'ingredients' => 'Chicken, Rice, Carrots',
        ]);

        $this->parser->parseAndSync($grainFree);
        $this->parser->parseAndSync($withGrain);

        $results = ProductListing::grainFree()->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($grainFree->id);
    });

    test('withoutAllergen scope filters by allergen', function () {
        $noChicken = ProductListing::factory()->create([
            'ingredients' => 'Beef, Rice, Carrots',
        ]);
        $withChicken = ProductListing::factory()->create([
            'ingredients' => 'Chicken, Rice, Carrots',
        ]);

        $this->parser->parseAndSync($noChicken);
        $this->parser->parseAndSync($withChicken);

        $results = ProductListing::withoutAllergen(Allergen::Chicken)->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($noChicken->id);
    });

    test('withAllergen scope filters by allergen', function () {
        $noChicken = ProductListing::factory()->create([
            'ingredients' => 'Beef, Rice, Carrots',
        ]);
        $withChicken = ProductListing::factory()->create([
            'ingredients' => 'Chicken, Rice, Carrots',
        ]);

        $this->parser->parseAndSync($noChicken);
        $this->parser->parseAndSync($withChicken);

        $results = ProductListing::withAllergen(Allergen::Chicken)->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($withChicken->id);
    });

    test('singleProtein scope filters single protein products', function () {
        $singleProtein = ProductListing::factory()->create([
            'ingredients' => 'Chicken, Rice, Carrots',
        ]);
        $multiProtein = ProductListing::factory()->create([
            'ingredients' => 'Chicken, Beef, Rice',
        ]);

        $this->parser->parseAndSync($singleProtein);
        $this->parser->parseAndSync($multiProtein);

        $results = ProductListing::singleProtein()->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($singleProtein->id);
    });
});
