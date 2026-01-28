<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\VerificationStatus;
use Illuminate\Foundation\Http\FormRequest;

class ApproveMatchRequest extends FormRequest
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
        return [];
    }
}
