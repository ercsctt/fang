<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductExport>
 */
class ProductExportFactory extends Factory
{
    protected $model = ProductExport::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['products', 'prices']),
            'format' => fake()->randomElement(['csv', 'json']),
            'status' => 'pending',
            'filters' => [],
            'file_path' => null,
            'file_name' => null,
            'file_size_bytes' => null,
            'row_count' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'file_path' => 'exports/'.fake()->uuid().'.'.fake()->randomElement(['csv', 'json']),
            'file_name' => fake()->uuid().'.'.fake()->randomElement(['csv', 'json']),
            'file_size_bytes' => fake()->numberBetween(1000, 1000000),
            'row_count' => fake()->numberBetween(1, 1000),
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }

    public function processing(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'started_at' => now()->subMinutes(2),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => fake()->sentence(),
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }
}
