<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Allergen;
use App\Models\Ingredient;
use App\Models\ProductListing;

class IngredientsParser
{
    /**
     * Parse an ingredients string into individual ingredient names.
     *
     * @return list<array{name: string, percentage: string|null, position: int}>
     */
    public function parseIngredientsString(?string $ingredientsText): array
    {
        if ($ingredientsText === null || trim($ingredientsText) === '') {
            return [];
        }

        $text = $this->cleanIngredientsText($ingredientsText);
        $rawIngredients = $this->splitIngredients($text);
        $parsedIngredients = [];

        foreach ($rawIngredients as $position => $rawIngredient) {
            $parsed = $this->parseIngredient($rawIngredient, $position);
            if ($parsed !== null) {
                $parsedIngredients[] = $parsed;
            }
        }

        return $parsedIngredients;
    }

    /**
     * Detect allergens present in an ingredients list.
     *
     * @param  list<array{name: string, percentage: string|null, position: int}>  $ingredients
     * @return list<Allergen>
     */
    public function detectAllergens(array $ingredients): array
    {
        $detectedAllergens = [];
        $keywordMap = Allergen::keywordMap();

        foreach ($ingredients as $ingredient) {
            $normalizedName = mb_strtolower($ingredient['name']);

            foreach ($keywordMap as $keyword => $allergen) {
                if (str_contains($normalizedName, $keyword)) {
                    if (! in_array($allergen, $detectedAllergens, true)) {
                        $detectedAllergens[] = $allergen;
                    }
                }
            }
        }

        return $detectedAllergens;
    }

    /**
     * Detect allergens from a raw ingredients string.
     *
     * @return list<Allergen>
     */
    public function detectAllergensFromText(?string $ingredientsText): array
    {
        $ingredients = $this->parseIngredientsString($ingredientsText);

        return $this->detectAllergens($ingredients);
    }

    /**
     * Check if the product is grain-free based on ingredients.
     *
     * @param  list<Allergen>  $allergens
     */
    public function isGrainFree(array $allergens): bool
    {
        $grainAllergens = [Allergen::Grain, Allergen::Wheat, Allergen::Corn];

        foreach ($grainAllergens as $grainAllergen) {
            if (in_array($grainAllergen, $allergens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the product is single-protein based on ingredients.
     *
     * @param  list<Allergen>  $allergens
     */
    public function isSingleProtein(array $allergens): bool
    {
        $proteinAllergens = [
            Allergen::Chicken,
            Allergen::Beef,
            Allergen::Pork,
            Allergen::Fish,
            Allergen::Lamb,
        ];

        $proteinCount = 0;
        foreach ($proteinAllergens as $protein) {
            if (in_array($protein, $allergens, true)) {
                $proteinCount++;
            }
        }

        return $proteinCount <= 1;
    }

    /**
     * Get the primary protein from detected allergens.
     *
     * @param  list<Allergen>  $allergens
     */
    public function getPrimaryProtein(array $allergens): ?Allergen
    {
        $proteinAllergens = [
            Allergen::Chicken,
            Allergen::Beef,
            Allergen::Pork,
            Allergen::Fish,
            Allergen::Lamb,
        ];

        foreach ($proteinAllergens as $protein) {
            if (in_array($protein, $allergens, true)) {
                return $protein;
            }
        }

        return null;
    }

    /**
     * Parse a product listing's ingredients and sync to the database.
     *
     * @return array{ingredients: list<Ingredient>, allergens: list<Allergen>}
     */
    public function parseAndSync(ProductListing $productListing): array
    {
        $parsed = $this->parseIngredientsString($productListing->ingredients);
        $allergens = $this->detectAllergens($parsed);

        $ingredientModels = [];
        $syncData = [];

        foreach ($parsed as $item) {
            $ingredientAllergens = $this->detectAllergenForIngredient($item['name']);

            $ingredient = Ingredient::findOrCreateByName(
                $item['name'],
                $this->categorizeIngredient($item['name']),
                $ingredientAllergens
            );

            $ingredientModels[] = $ingredient;
            $syncData[$ingredient->id] = [
                'position' => $item['position'],
                'percentage' => $item['percentage'],
            ];
        }

        $productListing->ingredientRelation()->sync($syncData);

        return [
            'ingredients' => $ingredientModels,
            'allergens' => $allergens,
        ];
    }

    /**
     * Clean the raw ingredients text for parsing.
     */
    private function cleanIngredientsText(string $text): string
    {
        $text = preg_replace('/ingredients\s*[:.]?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/composition\s*[:.]?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Split ingredients text into individual ingredients.
     *
     * @return list<string>
     */
    private function splitIngredients(string $text): array
    {
        $ingredients = preg_split('/[,;](?![^()]*\))/', $text) ?? [$text];

        return array_values(array_filter(
            array_map(fn (string $i) => trim($i), $ingredients),
            fn (string $i) => $i !== ''
        ));
    }

    /**
     * Parse a single ingredient string.
     *
     * @return array{name: string, percentage: string|null, position: int}|null
     */
    private function parseIngredient(string $raw, int $position): ?array
    {
        $name = trim($raw);

        if ($name === '') {
            return null;
        }

        $percentage = null;
        if (preg_match('/\(?\s*(\d+(?:\.\d+)?)\s*%\s*\)?/', $name, $matches)) {
            $percentage = $matches[1].'%';
            $name = trim(preg_replace('/\(?\s*\d+(?:\.\d+)?\s*%\s*\)?/', '', $name) ?? $name);
        }

        $name = preg_replace('/\([^)]*\)/', '', $name) ?? $name;
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'percentage' => $percentage,
            'position' => $position,
        ];
    }

    /**
     * Detect allergens for a single ingredient.
     *
     * @return list<Allergen>
     */
    private function detectAllergenForIngredient(string $ingredientName): array
    {
        $allergens = [];
        $keywordMap = Allergen::keywordMap();
        $normalizedName = mb_strtolower($ingredientName);

        foreach ($keywordMap as $keyword => $allergen) {
            if (str_contains($normalizedName, $keyword)) {
                if (! in_array($allergen, $allergens, true)) {
                    $allergens[] = $allergen;
                }
            }
        }

        return $allergens;
    }

    /**
     * Categorize an ingredient based on its name.
     *
     * @return string|null One of: protein, carbohydrate, fat, vegetable, fruit, supplement, preservative, or null
     */
    private function categorizeIngredient(string $ingredientName): ?string
    {
        $name = mb_strtolower($ingredientName);

        $categories = [
            'protein' => [
                'chicken', 'beef', 'lamb', 'salmon', 'fish', 'turkey', 'duck', 'venison',
                'rabbit', 'pork', 'meat', 'liver', 'heart', 'meal', 'protein', 'egg', 'whey',
            ],
            'carbohydrate' => [
                'rice', 'barley', 'oat', 'wheat', 'corn', 'maize', 'potato', 'sweet potato',
                'pea', 'lentil', 'chickpea', 'tapioca', 'quinoa',
            ],
            'fat' => [
                'fat', 'oil', 'flaxseed', 'linseed', 'sunflower', 'coconut oil',
            ],
            'vegetable' => [
                'carrot', 'spinach', 'broccoli', 'pumpkin', 'zucchini', 'beet', 'celery',
            ],
            'fruit' => [
                'apple', 'blueberry', 'cranberry', 'banana', 'berry',
            ],
            'supplement' => [
                'vitamin', 'mineral', 'calcium', 'phosphorus', 'zinc', 'iron', 'copper',
                'manganese', 'selenium', 'iodine', 'taurine', 'choline',
            ],
            'preservative' => [
                'tocopherol', 'rosemary extract', 'citric acid', 'mixed tocopherols',
            ],
        ];

        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }
}
