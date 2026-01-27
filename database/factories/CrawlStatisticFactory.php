<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CrawlStatistic;
use App\Models\Retailer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawlStatistic>
 */
class CrawlStatisticFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = fake()->numberBetween(10, 100);
        $completed = fake()->numberBetween(0, $started);
        $failed = $started - $completed;

        return [
            'retailer_id' => Retailer::factory(),
            'date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'crawls_started' => $started,
            'crawls_completed' => $completed,
            'crawls_failed' => $failed,
            'listings_discovered' => fake()->numberBetween(0, 500),
            'details_extracted' => fake()->numberBetween(0, 500),
            'average_duration_ms' => fake()->optional(0.8)->numberBetween(500, 30000),
        ];
    }

    /**
     * Indicate a perfect day with no failures.
     */
    public function perfect(): static
    {
        $count = fake()->numberBetween(10, 100);

        return $this->state(fn (array $attributes): array => [
            'crawls_started' => $count,
            'crawls_completed' => $count,
            'crawls_failed' => 0,
        ]);
    }

    /**
     * Indicate a day with all failures.
     */
    public function allFailed(): static
    {
        $count = fake()->numberBetween(10, 100);

        return $this->state(fn (array $attributes): array => [
            'crawls_started' => $count,
            'crawls_completed' => 0,
            'crawls_failed' => $count,
        ]);
    }

    /**
     * Set the date for this statistic.
     */
    public function forDate(string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'date' => $date,
        ]);
    }
}
