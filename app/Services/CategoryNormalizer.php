<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CanonicalCategory;

class CategoryNormalizer
{
    /**
     * @var array<string, CanonicalCategory>
     */
    private array $categoryMappings = [
        // Dry Food patterns
        'dry food' => CanonicalCategory::DryFood,
        'kibble' => CanonicalCategory::DryFood,
        'complete food' => CanonicalCategory::DryFood,
        'dry complete' => CanonicalCategory::DryFood,
        'adult dry' => CanonicalCategory::DryFood,
        'biscuits' => CanonicalCategory::DryFood,

        // Wet Food patterns
        'wet food' => CanonicalCategory::WetFood,
        'canned food' => CanonicalCategory::WetFood,
        'tinned food' => CanonicalCategory::WetFood,
        'pouches' => CanonicalCategory::WetFood,
        'tins' => CanonicalCategory::WetFood,
        'trays' => CanonicalCategory::WetFood,
        'loaf' => CanonicalCategory::WetFood,
        'pate' => CanonicalCategory::WetFood,
        'chunks in gravy' => CanonicalCategory::WetFood,
        'chunks in jelly' => CanonicalCategory::WetFood,

        // Treats patterns
        'treats' => CanonicalCategory::Treats,
        'snacks' => CanonicalCategory::Treats,
        'rewards' => CanonicalCategory::Treats,
        'training treats' => CanonicalCategory::Treats,
        'biscuit treats' => CanonicalCategory::Treats,
        'chews' => CanonicalCategory::Treats,
        'rawhide' => CanonicalCategory::Treats,

        // Dental patterns
        'dental' => CanonicalCategory::Dental,
        'dentastix' => CanonicalCategory::Dental,
        'dental chews' => CanonicalCategory::Dental,
        'dental sticks' => CanonicalCategory::Dental,
        'oral care' => CanonicalCategory::Dental,
        'teeth cleaning' => CanonicalCategory::Dental,

        // Puppy Food patterns
        'puppy' => CanonicalCategory::PuppyFood,
        'puppy food' => CanonicalCategory::PuppyFood,
        'junior' => CanonicalCategory::PuppyFood,
        'junior food' => CanonicalCategory::PuppyFood,
        'puppy complete' => CanonicalCategory::PuppyFood,

        // Senior Food patterns
        'senior' => CanonicalCategory::SeniorFood,
        'senior food' => CanonicalCategory::SeniorFood,
        'mature' => CanonicalCategory::SeniorFood,
        'mature food' => CanonicalCategory::SeniorFood,
        '7+' => CanonicalCategory::SeniorFood,
        '8+' => CanonicalCategory::SeniorFood,
        'senior complete' => CanonicalCategory::SeniorFood,
    ];

    /**
     * Normalize a retailer-specific category to a canonical category.
     */
    public function normalize(?string $category): CanonicalCategory
    {
        if ($category === null || trim($category) === '') {
            return CanonicalCategory::Other;
        }

        $normalized = strtolower(trim($category));

        // Direct match in mappings
        if (isset($this->categoryMappings[$normalized])) {
            return $this->categoryMappings[$normalized];
        }

        // Partial match - check if any pattern is contained in the category
        foreach ($this->categoryMappings as $pattern => $canonicalCategory) {
            if (str_contains($normalized, $pattern)) {
                return $canonicalCategory;
            }
        }

        return CanonicalCategory::Other;
    }

    /**
     * Normalize a category based on both the category field and product title.
     * This provides more context for accurate categorization.
     */
    public function normalizeWithContext(?string $category, ?string $title): CanonicalCategory
    {
        // Try category first
        $fromCategory = $this->normalize($category);

        // If category is conclusive (not Other), use it
        if ($fromCategory !== CanonicalCategory::Other) {
            return $fromCategory;
        }

        // Try to infer from title
        if ($title !== null) {
            $fromTitle = $this->normalize($title);
            if ($fromTitle !== CanonicalCategory::Other) {
                return $fromTitle;
            }
        }

        return CanonicalCategory::Other;
    }

    /**
     * Get all category mappings for inspection/debugging.
     *
     * @return array<string, CanonicalCategory>
     */
    public function getMappings(): array
    {
        return $this->categoryMappings;
    }

    /**
     * Add a custom category mapping.
     */
    public function addMapping(string $pattern, CanonicalCategory $category): void
    {
        $this->categoryMappings[strtolower(trim($pattern))] = $category;
    }
}
