<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserVerificationStat>
 */
class UserVerificationStatFactory extends Factory
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
            'date' => fake()->dateTimeBetween('-30 days', 'now'),
            'matches_approved' => fake()->numberBetween(0, 100),
            'matches_rejected' => fake()->numberBetween(0, 20),
            'matches_rematched' => fake()->numberBetween(0, 10),
            'bulk_approvals' => fake()->numberBetween(0, 50),
        ];
    }

    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => today(),
        ]);
    }

    public function empty(): static
    {
        return $this->state(fn (array $attributes) => [
            'matches_approved' => 0,
            'matches_rejected' => 0,
            'matches_rematched' => 0,
            'bulk_approvals' => 0,
        ]);
    }
}
