<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Allergen;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    /**
     * Common dog food ingredients for realistic test data.
     *
     * @var list<array{name: string, category: string|null, allergens: list<string>}>
     */
    private const COMMON_INGREDIENTS = [
        ['name' => 'Chicken', 'category' => 'protein', 'allergens' => ['chicken']],
        ['name' => 'Chicken Meal', 'category' => 'protein', 'allergens' => ['chicken']],
        ['name' => 'Beef', 'category' => 'protein', 'allergens' => ['beef']],
        ['name' => 'Lamb', 'category' => 'protein', 'allergens' => ['lamb']],
        ['name' => 'Salmon', 'category' => 'protein', 'allergens' => ['fish']],
        ['name' => 'Fish Meal', 'category' => 'protein', 'allergens' => ['fish']],
        ['name' => 'Brown Rice', 'category' => 'carbohydrate', 'allergens' => ['grain']],
        ['name' => 'White Rice', 'category' => 'carbohydrate', 'allergens' => ['grain']],
        ['name' => 'Barley', 'category' => 'carbohydrate', 'allergens' => ['grain']],
        ['name' => 'Oatmeal', 'category' => 'carbohydrate', 'allergens' => ['grain']],
        ['name' => 'Corn', 'category' => 'carbohydrate', 'allergens' => ['corn', 'grain']],
        ['name' => 'Wheat', 'category' => 'carbohydrate', 'allergens' => ['wheat', 'grain']],
        ['name' => 'Sweet Potato', 'category' => 'carbohydrate', 'allergens' => []],
        ['name' => 'Potato', 'category' => 'carbohydrate', 'allergens' => []],
        ['name' => 'Peas', 'category' => 'carbohydrate', 'allergens' => []],
        ['name' => 'Lentils', 'category' => 'carbohydrate', 'allergens' => []],
        ['name' => 'Chicken Fat', 'category' => 'fat', 'allergens' => ['chicken']],
        ['name' => 'Salmon Oil', 'category' => 'fat', 'allergens' => ['fish']],
        ['name' => 'Flaxseed', 'category' => 'fat', 'allergens' => []],
        ['name' => 'Dried Egg Product', 'category' => 'protein', 'allergens' => ['egg']],
        ['name' => 'Soybean Meal', 'category' => 'protein', 'allergens' => ['soy']],
        ['name' => 'Whey', 'category' => 'protein', 'allergens' => ['dairy']],
        ['name' => 'Carrots', 'category' => 'vegetable', 'allergens' => []],
        ['name' => 'Spinach', 'category' => 'vegetable', 'allergens' => []],
        ['name' => 'Blueberries', 'category' => 'fruit', 'allergens' => []],
        ['name' => 'Cranberries', 'category' => 'fruit', 'allergens' => []],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ingredient = fake()->randomElement(self::COMMON_INGREDIENTS);

        return [
            'name' => $ingredient['name'],
            'normalized_name' => Ingredient::normalizeName($ingredient['name']),
            'category' => $ingredient['category'],
            'allergens' => $ingredient['allergens'],
        ];
    }

    /**
     * Create a protein ingredient.
     */
    public function protein(): static
    {
        return $this->state(fn () => [
            'name' => fake()->randomElement(['Chicken', 'Beef', 'Lamb', 'Salmon', 'Turkey', 'Duck']),
            'category' => 'protein',
        ]);
    }

    /**
     * Create a grain ingredient.
     */
    public function grain(): static
    {
        return $this->state(function () {
            $name = fake()->randomElement(['Brown Rice', 'Barley', 'Oatmeal', 'Wheat']);

            return [
                'name' => $name,
                'category' => 'carbohydrate',
                'allergens' => [Allergen::Grain->value],
            ];
        });
    }

    /**
     * Create a grain-free carbohydrate ingredient.
     */
    public function grainFree(): static
    {
        return $this->state(fn () => [
            'name' => fake()->randomElement(['Sweet Potato', 'Potato', 'Peas', 'Lentils', 'Chickpeas']),
            'category' => 'carbohydrate',
            'allergens' => [],
        ]);
    }

    /**
     * Create ingredient with specific allergen.
     */
    public function withAllergen(Allergen $allergen): static
    {
        return $this->state(fn () => [
            'allergens' => [$allergen->value],
        ]);
    }
}
