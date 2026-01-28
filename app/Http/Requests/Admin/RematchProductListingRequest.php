<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\VerificationStatus;
use Illuminate\Foundation\Http\FormRequest;

class RematchProductListingRequest extends FormRequest
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
            'product_id' => [
                'required',
                'integer',
                'exists:products,id',
                'different:match.product_id',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'You must select a product to rematch to.',
            'product_id.exists' => 'The selected product does not exist.',
            'product_id.different' => 'You must select a different product for rematch.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'match' => $this->route('match'),
        ]);
    }
}
