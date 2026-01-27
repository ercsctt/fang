<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RetailerHealthStatus;
use App\Models\Retailer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Retailer>
 */
class RetailerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();
        $domain = Str::slug($name).'.co.uk';

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'base_url' => 'https://www.'.$domain,
            'crawler_class' => null,
            'is_active' => true,
            'health_status' => RetailerHealthStatus::Healthy,
            'consecutive_failures' => 0,
            'last_failure_at' => null,
            'paused_until' => null,
            'rate_limit_ms' => fake()->randomElement([500, 1000, 1500, 2000]),
            'last_crawled_at' => fake()->optional(0.5)->dateTimeBetween('-7 days', 'now'),
        ];
    }

    /**
     * Indicate that the retailer is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the retailer has a crawler class configured.
     */
    public function withCrawler(string $crawlerClass): static
    {
        return $this->state(fn (array $attributes): array => [
            'crawler_class' => $crawlerClass,
        ]);
    }

    /**
     * Indicate that the retailer has a degraded health status.
     */
    public function degraded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'health_status' => RetailerHealthStatus::Degraded,
            'consecutive_failures' => 5,
        ]);
    }

    /**
     * Indicate that the retailer has an unhealthy status.
     */
    public function unhealthy(): static
    {
        return $this->state(fn (array $attributes): array => [
            'health_status' => RetailerHealthStatus::Unhealthy,
            'consecutive_failures' => 10,
        ]);
    }

    /**
     * Indicate that the retailer is currently paused.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'health_status' => RetailerHealthStatus::Unhealthy,
            'consecutive_failures' => 10,
            'paused_until' => now()->addHour(),
        ]);
    }
}
