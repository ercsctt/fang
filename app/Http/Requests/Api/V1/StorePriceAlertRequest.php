<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceAlertRequest extends FormRequest
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
            'product_id' => [
                'required',
                'integer',
                'exists:products,id',
                Rule::unique('price_alerts')->where(function ($query) {
                    return $query->where('user_id', $this->user()->id);
                }),
            ],
            'target_price_pence' => [
                'required',
                'integer',
                'min:1',
                'max:99999999',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.unique' => 'You already have a price alert for this product.',
            'product_id.exists' => 'The selected product does not exist.',
            'target_price_pence.min' => 'The target price must be at least 1 pence.',
        ];
    }
}
