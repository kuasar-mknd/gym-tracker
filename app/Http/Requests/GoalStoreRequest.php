<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoalStoreRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:weight,frequency,volume,measurement'],
            'target_value' => ['required', 'numeric', 'min:0'],
            'exercise_id' => [
                'required_if:type,weight,volume',
                'nullable',
                Rule::exists('exercises', 'id')->where(function ($query) {
                    $query->where(function ($q) {
                        $q->whereNull('user_id')->orWhere('user_id', $this->user()->id);
                    });
                }),
            ],
            'measurement_type' => ['required_if:type,measurement', 'nullable', 'string'],
            'deadline' => ['nullable', 'date', 'after:today'],
            'start_value' => ['nullable', 'numeric'],
        ];
    }
}
