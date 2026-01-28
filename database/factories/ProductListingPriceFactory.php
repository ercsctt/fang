<?php

namespace Database\Factories;

use App\Models\ProductListing;
use App\Models\ProductListingPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductListingPrice>
 */
class ProductListingPriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pricePence = fake()->numberBetween(100, 10000);
        $hasDiscount = fake()->boolean(30);

        return [
            'product_listing_id' => ProductListing::factory(),
            'price_pence' => $pricePence,
            'original_price_pence' => $hasDiscount ? fake()->numberBetween($pricePence + 100, $pricePence + 2000) : null,
            'currency' => 'GBP',
            'recorded_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }

    public function onSale(int $discountPence = 500): static
    {
        return $this->state(function (array $attributes) use ($discountPence) {
            return [
                'original_price_pence' => $attributes['price_pence'] + $discountPence,
            ];
        });
    }

    public function fullPrice(): static
    {
        return $this->state(fn () => [
            'original_price_pence' => null,
        ]);
    }
}
