<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplementLogStoreRequest extends FormRequest
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
            'supplement_id' => [
                'required',
                'integer',
                Rule::exists('supplements', 'id')->where(function ($query) {
                    $user = $this->user();
                    return $query->where('user_id', $user?->id);
                }),
            ],
            'quantity' => ['required', 'integer', 'min:1'],
            'consumed_at' => ['required', 'date'],
        ];
    }
}
