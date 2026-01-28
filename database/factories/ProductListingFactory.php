<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductListing;
use App\Models\Retailer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductListing>
 */
class ProductListingFactory extends Factory
{
    /**
     * @var list<string>
     */
    private array $brands = [
        'Pedigree',
        'Royal Canin',
        'Hills Science Diet',
        'Purina Pro Plan',
        'Iams',
        'Eukanuba',
        'Orijen',
        'Acana',
        'Blue Buffalo',
        'Wellness',
        'Canagan',
        'Lily\'s Kitchen',
        'Forthglade',
        'Harringtons',
        'Wagg',
    ];

    /**
     * @var list<string>
     */
    private array $categories = [
        'Dry Dog Food',
        'Wet Dog Food',
        'Dog Treats',
        'Puppy Food',
        'Senior Dog Food',
        'Grain-Free Dog Food',
        'Natural Dog Food',
        'Dental Treats',
        'Training Treats',
    ];

    /**
     * @var list<string>
     */
    private array $productTypes = [
        'Complete Dry Dog Food',
        'Wet Dog Food Pouches',
        'Dog Treats',
        'Dental Sticks',
        'Puppy Food',
        'Senior Dog Food',
        'Grain-Free Kibble',
    ];

    /**
     * @var list<string>
     */
    private array $flavours = [
        'Chicken',
        'Beef',
        'Lamb',
        'Salmon',
        'Duck',
        'Turkey',
        'Venison',
        'Rabbit',
        'Fish',
        'Mixed Meat',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $brand = fake()->randomElement($this->brands);
        $productType = fake()->randomElement($this->productTypes);
        $flavour = fake()->randomElement($this->flavours);
        $weightGrams = fake()->randomElement([400, 800, 1500, 2000, 2500, 5000, 10000, 15000]);
        $pricePence = fake()->numberBetween(199, 4999);
        $isOnSale = fake()->boolean(20);

        return [
            'retailer_id' => Retailer::factory(),
            'external_id' => fake()->optional(0.8)->uuid(),
            'url' => fake()->url(),
            'title' => sprintf('%s %s %s %dg', $brand, $flavour, $productType, $weightGrams),
            'description' => fake()->optional(0.9)->paragraphs(2, true),
            'price_pence' => $pricePence,
            'original_price_pence' => $isOnSale ? $pricePence + fake()->numberBetween(100, 500) : null,
            'currency' => 'GBP',
            'weight_grams' => $weightGrams,
            'quantity' => fake()->optional(0.3)->numberBetween(1, 12),
            'brand' => $brand,
            'category' => fake()->randomElement($this->categories),
            'images' => fake()->optional(0.8)->randomElements([
                'https://example.com/images/product1.jpg',
                'https://example.com/images/product2.jpg',
                'https://example.com/images/product3.jpg',
            ], fake()->numberBetween(1, 3)),
            'ingredients' => fake()->optional(0.7)->sentence(20),
            'nutritional_info' => fake()->optional(0.5)->randomElement([
                ['protein' => '25%', 'fat' => '15%', 'fibre' => '3%', 'moisture' => '10%'],
                ['protein' => '28%', 'fat' => '18%', 'fibre' => '2.5%', 'moisture' => '8%'],
                ['protein' => '22%', 'fat' => '12%', 'fibre' => '4%', 'moisture' => '12%'],
            ]),
            'in_stock' => fake()->boolean(90),
            'stock_quantity' => fake()->optional(0.3)->numberBetween(0, 100),
            'last_scraped_at' => fake()->optional(0.8)->dateTimeBetween('-7 days', 'now'),
        ];
    }

    public function inStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'in_stock' => true,
            'stock_quantity' => fake()->numberBetween(1, 100),
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'in_stock' => false,
            'stock_quantity' => 0,
        ]);
    }

    public function onSale(): static
    {
        return $this->state(function (array $attributes): array {
            $pricePence = $attributes['price_pence'] ?? fake()->numberBetween(199, 4999);

            return [
                'price_pence' => $pricePence,
                'original_price_pence' => $pricePence + fake()->numberBetween(100, 1000),
            ];
        });
    }

    public function needsScraping(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_scraped_at' => fake()->dateTimeBetween('-30 days', '-25 hours'),
        ]);
    }

    public function recentlyScraped(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_scraped_at' => fake()->dateTimeBetween('-1 hour', 'now'),
        ]);
    }
}
