<?php

namespace Database\Factories;

use App\Enums\MatchType;
use App\Enums\VerificationStatus;
use App\Models\Product;
use App\Models\ProductListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductListingMatch>
 */
class ProductListingMatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_listing_id' => ProductListing::factory(),
            'confidence_score' => fake()->randomFloat(2, 50, 100),
            'match_type' => fake()->randomElement(MatchType::cases()),
            'matched_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'verified_by' => null,
            'verified_at' => null,
            'status' => VerificationStatus::Pending,
            'rejection_reason' => null,
        ];
    }

    public function verified(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_by' => $user?->id ?? User::factory(),
            'verified_at' => fake()->dateTimeBetween('-1 week', 'now'),
            'status' => VerificationStatus::Approved,
        ]);
    }

    public function approved(?User $user = null): static
    {
        return $this->verified($user);
    }

    public function rejected(?User $user = null, ?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_by' => $user?->id ?? User::factory(),
            'verified_at' => fake()->dateTimeBetween('-1 week', 'now'),
            'status' => VerificationStatus::Rejected,
            'rejection_reason' => $reason ?? fake()->sentence(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VerificationStatus::Pending,
            'verified_by' => null,
            'verified_at' => null,
            'rejection_reason' => null,
        ]);
    }

    public function highConfidence(): static
    {
        return $this->state(fn (array $attributes) => [
            'confidence_score' => fake()->randomFloat(2, 95, 100),
        ]);
    }

    public function lowConfidence(): static
    {
        return $this->state(fn (array $attributes) => [
            'confidence_score' => fake()->randomFloat(2, 30, 69),
        ]);
    }

    public function exact(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_type' => MatchType::Exact,
        ]);
    }

    public function fuzzy(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_type' => MatchType::Fuzzy,
        ]);
    }

    public function barcode(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_type' => MatchType::Barcode,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_type' => MatchType::Manual,
        ]);
    }
}
