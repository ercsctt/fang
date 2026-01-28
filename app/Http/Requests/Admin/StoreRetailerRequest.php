<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\RetailerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRetailerRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', 'unique:retailers,name'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', 'unique:retailers,slug'],
            'base_url' => ['required', 'url', 'max:255'],
            'crawler_class' => ['required', 'string', 'max:255'],
            'rate_limit_ms' => ['required', 'integer', 'min:100', 'max:60000'],
            'status' => ['required', Rule::enum(RetailerStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The retailer name is required.',
            'name.unique' => 'A retailer with this name already exists.',
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
            'slug.unique' => 'A retailer with this slug already exists.',
            'base_url.required' => 'The base URL is required.',
            'base_url.url' => 'The base URL must be a valid URL.',
            'crawler_class.required' => 'Please select a crawler class.',
            'rate_limit_ms.required' => 'The rate limit is required.',
            'rate_limit_ms.min' => 'The rate limit must be at least 100ms.',
            'rate_limit_ms.max' => 'The rate limit cannot exceed 60000ms (60 seconds).',
            'status.required' => 'Please select an initial status.',
        ];
    }
}
