<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ImageCache;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageCache>
 */
class ImageCacheFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hash = fake()->sha256();

        return [
            'original_url' => fake()->imageUrl(),
            'cached_path' => "images/{$hash}.jpg",
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => fake()->numberBetween(10000, 500000),
            'width' => fake()->randomElement([200, 400, 800, 1200]),
            'height' => fake()->randomElement([200, 400, 800, 1200]),
            'last_fetched_at' => fake()->dateTimeBetween('-7 days', 'now'),
            'fetch_count' => fake()->numberBetween(1, 100),
        ];
    }

    public function orphaned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_fetched_at' => fake()->dateTimeBetween('-60 days', '-31 days'),
            'fetch_count' => 0,
        ]);
    }

    public function recentlyFetched(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_fetched_at' => fake()->dateTimeBetween('-1 hour', 'now'),
        ]);
    }
}
