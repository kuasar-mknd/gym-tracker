<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreWilksScoreRequest extends FormRequest
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
            'body_weight' => ['required', 'numeric', 'gt:0'],
            'lifted_weight' => ['required', 'numeric', 'gt:0'],
            'gender' => ['required', 'string', 'in:male,female'],
            'unit' => ['required', 'string', 'in:kg,lbs'],
            'score' => ['required', 'numeric'],
        ];
    }
}
