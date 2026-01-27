<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PriceAlert;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceAlert>
 */
class PriceAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'target_price_pence' => fake()->numberBetween(100, 5000),
            'is_active' => true,
            'last_notified_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function notifiedRecently(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_notified_at' => now()->subHours(6),
        ]);
    }
}
