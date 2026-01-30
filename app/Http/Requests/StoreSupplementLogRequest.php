<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplementLogRequest extends FormRequest
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
                    return $query->where('user_id', $this->user()->id);
                }),
            ],
            'quantity' => ['required', 'integer', 'min:1'],
            'consumed_at' => ['required', 'date'],
        ];
    }
}
