<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BulkApproveMatchesRequest extends FormRequest
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
            'min_confidence' => [
                'sometimes',
                'numeric',
                'min:80',
                'max:100',
            ],
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:500',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'min_confidence.min' => 'Minimum confidence for bulk approval must be at least 80%.',
            'min_confidence.max' => 'Minimum confidence cannot exceed 100%.',
            'limit.max' => 'Cannot bulk approve more than 500 matches at once.',
        ];
    }
}
