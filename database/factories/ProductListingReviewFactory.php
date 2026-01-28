<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductListing;
use App\Models\ProductListingReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductListingReview>
 */
class ProductListingReviewFactory extends Factory
{
    /**
     * @var list<string>
     */
    private array $positiveReviews = [
        'My dog absolutely loves this food! He cleans his bowl every single time and has so much more energy.',
        'Finally found a food that doesn\'t upset my pup\'s sensitive stomach. Highly recommend for dogs with digestive issues.',
        'Great quality ingredients and my dog\'s coat has never looked shinier. Will definitely repurchase.',
        'Excellent value for money. My two labs gobble it up and they\'ve both maintained a healthy weight.',
        'After trying dozens of brands, this is the one my fussy eater actually enjoys. Five stars!',
        'The kibble size is perfect for my small breed. She has no trouble chewing it.',
        'My elderly dog has been on this for six months now and his joint mobility has improved noticeably.',
        'Vet recommended this brand and I can see why. My dog\'s dental health has improved significantly.',
    ];

    /**
     * @var list<string>
     */
    private array $neutralReviews = [
        'Decent food for the price. My dog eats it without complaint but nothing special.',
        'It\'s okay. Took my dog a few days to get used to it but now she eats it fine.',
        'Good quality but a bit expensive compared to similar brands. Might try something cheaper next time.',
        'My dog likes it some days and leaves it other days. Hard to say if it\'s worth the price.',
    ];

    /**
     * @var list<string>
     */
    private array $negativeReviews = [
        'Unfortunately my dog had an allergic reaction to this food. Had to switch back to our old brand.',
        'The kibble is too large for my small dog. She struggles to eat it.',
        'My dog refused to eat this. Complete waste of money.',
        'Caused digestive issues for my pup. Would not recommend for sensitive stomachs.',
    ];

    /**
     * @var list<string>
     */
    private array $reviewTitles = [
        'Perfect for my pup!',
        'Good value for money',
        'My dog loves it',
        'Decent quality',
        'Not for us',
        'Highly recommend',
        'Great ingredients',
        'Mixed feelings',
        'Will buy again',
        'Disappointed',
        'Exceeded expectations',
        'Just okay',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rating = fake()->randomFloat(1, 1.0, 5.0);

        return [
            'product_listing_id' => ProductListing::factory(),
            'external_id' => fake()->unique()->uuid(),
            'author' => fake()->optional(0.9)->name(),
            'rating' => $rating,
            'title' => fake()->optional(0.7)->randomElement($this->reviewTitles),
            'body' => $this->getReviewBody($rating),
            'verified_purchase' => fake()->boolean(70),
            'review_date' => fake()->optional(0.9)->dateTimeBetween('-2 years', 'now'),
            'helpful_count' => fake()->numberBetween(0, 150),
            'metadata' => fake()->optional(0.3)->passthrough([
                'source' => fake()->randomElement(['website', 'app', 'email']),
                'images_count' => fake()->numberBetween(0, 5),
            ]),
        ];
    }

    /**
     * Get an appropriate review body based on the rating.
     */
    private function getReviewBody(float $rating): string
    {
        if ($rating >= 4.0) {
            return fake()->randomElement($this->positiveReviews);
        }

        if ($rating >= 2.5) {
            return fake()->randomElement($this->neutralReviews);
        }

        return fake()->randomElement($this->negativeReviews);
    }

    /**
     * Create a verified purchase review.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verified_purchase' => true,
        ]);
    }

    /**
     * Create a high-rated review (4.0+).
     */
    public function highRated(): static
    {
        return $this->state(function (array $attributes): array {
            $rating = fake()->randomFloat(1, 4.0, 5.0);

            return [
                'rating' => $rating,
                'body' => fake()->randomElement($this->positiveReviews),
            ];
        });
    }

    /**
     * Create a low-rated review (below 2.5).
     */
    public function lowRated(): static
    {
        return $this->state(function (array $attributes): array {
            $rating = fake()->randomFloat(1, 1.0, 2.4);

            return [
                'rating' => $rating,
                'body' => fake()->randomElement($this->negativeReviews),
            ];
        });
    }

    /**
     * Create a recent review (within the last 30 days).
     */
    public function recent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'review_date' => fake()->dateTimeBetween('-30 days', 'now'),
        ]);
    }
}
