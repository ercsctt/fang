<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Allergen;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Ingredient extends Model
{
    /** @use HasFactory<\Database\Factories\IngredientFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'normalized_name',
        'category',
        'allergens',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allergens' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Ingredient $ingredient): void {
            if (empty($ingredient->normalized_name)) {
                $ingredient->normalized_name = self::normalizeName($ingredient->name);
            }
        });
    }

    /**
     * @return BelongsToMany<ProductListing, $this>
     */
    public function productListings(): BelongsToMany
    {
        return $this->belongsToMany(ProductListing::class, 'product_listing_ingredients')
            ->withPivot(['position', 'percentage'])
            ->withTimestamps();
    }

    /**
     * Normalize an ingredient name for consistent storage and matching.
     */
    public static function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized) ?? $normalized;

        return Str::slug($normalized, '_');
    }

    /**
     * Check if ingredient contains a specific allergen.
     */
    public function hasAllergen(Allergen $allergen): bool
    {
        $allergens = $this->allergens ?? [];

        return in_array($allergen->value, $allergens, true);
    }

    /**
     * Get allergen enums for this ingredient.
     *
     * @return list<Allergen>
     */
    public function getAllergenEnums(): array
    {
        $allergenValues = $this->allergens ?? [];
        $enums = [];

        foreach ($allergenValues as $value) {
            $allergen = Allergen::tryFrom($value);
            if ($allergen !== null) {
                $enums[] = $allergen;
            }
        }

        return $enums;
    }

    /**
     * Find or create an ingredient by name.
     *
     * @param  list<Allergen>  $allergens
     */
    public static function findOrCreateByName(string $name, ?string $category = null, array $allergens = []): self
    {
        $normalizedName = self::normalizeName($name);

        $ingredient = self::query()
            ->where('normalized_name', $normalizedName)
            ->first();

        if ($ingredient !== null) {
            return $ingredient;
        }

        return self::create([
            'name' => $name,
            'normalized_name' => $normalizedName,
            'category' => $category,
            'allergens' => array_map(fn (Allergen $a) => $a->value, $allergens),
        ]);
    }
}
