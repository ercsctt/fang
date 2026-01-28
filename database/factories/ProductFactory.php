<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @var list<string>
     */
    private array $brands = [
        'Pedigree',
        'Bakers',
        'Harringtons',
        "Lily's Kitchen",
        'James Wellbeloved',
        'Burns',
        'Forthglade',
    ];

    /**
     * @var list<string>
     */
    private array $productTypes = [
        'Complete Dry Dog Food',
        'Wet Dog Food',
        'Grain Free Dog Food',
        'Puppy Food',
        'Senior Dog Food',
        'Dog Treats',
        'Dental Sticks',
        'Training Treats',
        'Natural Dog Food',
        'Working Dog Food',
    ];

    /**
     * @var list<string>
     */
    private array $flavours = [
        'Chicken',
        'Beef',
        'Lamb',
        'Turkey',
        'Duck',
        'Salmon',
        'Venison',
        'Pork',
        'Fish',
        'Mixed Meat',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $brand = fake()->randomElement($this->brands);
        $productType = fake()->randomElement($this->productTypes);
        $flavour = fake()->randomElement($this->flavours);
        $uniqueSuffix = fake()->unique()->numerify('####');
        $name = "{$brand} {$flavour} {$productType} {$uniqueSuffix}";

        $lowestPricePence = fake()->numberBetween(299, 4999);
        $averagePricePence = fake()->numberBetween($lowestPricePence, $lowestPricePence + 500);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'brand' => $brand,
            'description' => fake()->paragraph(),
            'category' => 'Dog Food',
            'subcategory' => $productType,
            'weight_grams' => fake()->randomElement([400, 800, 1000, 2000, 2500, 5000, 10000, 12000, 15000]),
            'quantity' => fake()->randomElement([1, 4, 6, 12, 24]),
            'primary_image' => fake()->imageUrl(640, 480, 'dog food'),
            'average_price_pence' => $averagePricePence,
            'lowest_price_pence' => $lowestPricePence,
            'is_verified' => fake()->boolean(30),
            'metadata' => [
                'life_stage' => fake()->randomElement(['Puppy', 'Adult', 'Senior', 'All Life Stages']),
                'dog_size' => fake()->randomElement(['Small', 'Medium', 'Large', 'All Sizes']),
            ],
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_verified' => true,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_verified' => false,
        ]);
    }
}
