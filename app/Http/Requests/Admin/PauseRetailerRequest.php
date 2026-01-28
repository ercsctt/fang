<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PauseRetailerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pause', $this->route('retailer'));
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'], // max 30 days
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'duration_minutes.integer' => 'The pause duration must be a valid number of minutes.',
            'duration_minutes.min' => 'The pause duration must be at least 1 minute.',
            'duration_minutes.max' => 'The pause duration cannot exceed 30 days (43,200 minutes).',
            'reason.max' => 'The reason cannot exceed 500 characters.',
        ];
    }
}
