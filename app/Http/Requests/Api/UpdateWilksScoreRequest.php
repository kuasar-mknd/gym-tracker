<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWilksScoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body_weight' => ['sometimes', 'numeric', 'gt:0'],
            'lifted_weight' => ['sometimes', 'numeric', 'gt:0'],
            'gender' => ['sometimes', 'string', 'in:male,female'],
            'unit' => ['sometimes', 'string', 'in:kg,lbs'],
            'score' => ['sometimes', 'numeric'],
        ];
    }
}
