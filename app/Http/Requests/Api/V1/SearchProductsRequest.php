<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CanonicalCategory;
use App\Services\Search\ProductSearchFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'canonical_category' => ['sometimes', 'nullable', Rule::enum(CanonicalCategory::class)],
            'min_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_price' => ['sometimes', 'nullable', 'integer', 'min:0', 'gte:min_price'],
            'in_stock' => ['sometimes', 'nullable', 'boolean'],
            'verified' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required' => 'A search query is required.',
            'q.min' => 'Search query must be at least 2 characters.',
            'q.max' => 'Search query must not exceed 200 characters.',
            'max_price.gte' => 'Maximum price must be greater than or equal to minimum price.',
        ];
    }

    public function toFilters(): ProductSearchFilters
    {
        return ProductSearchFilters::fromArray([
            'query' => $this->input('q'),
            'brand' => $this->input('brand'),
            'category' => $this->input('category'),
            'canonical_category' => $this->input('canonical_category'),
            'min_price' => $this->input('min_price'),
            'max_price' => $this->input('max_price'),
            'in_stock' => $this->has('in_stock') ? $this->boolean('in_stock') : null,
            'verified' => $this->has('verified') ? $this->boolean('verified') : null,
            'per_page' => $this->integer('per_page', 15),
            'page' => $this->integer('page', 1),
        ]);
    }
}
