<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\VerificationStatus;
use Illuminate\Foundation\Http\FormRequest;

class RejectMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $match = $this->route('match');

        return $match && $match->status === VerificationStatus::Pending;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.max' => 'The rejection reason must not exceed 1000 characters.',
        ];
    }
}
