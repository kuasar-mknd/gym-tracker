<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplementLogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('supplement_log'));
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
                'sometimes',
                'integer',
                Rule::exists('supplements', 'id')->where(function ($query) {
                    return $query->where('user_id', $this->user()->id);
                }),
            ],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'consumed_at' => ['sometimes', 'date'],
        ];
    }
}
