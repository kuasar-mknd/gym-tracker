<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExerciseUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('exercise')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('exercises')->ignore($this->exercise)],
            'type' => ['sometimes', 'required', Rule::in(['strength', 'cardio', 'timed'])],
            'category' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de l\'exercice est requis.',
            'name.unique' => 'Un exercice avec ce nom existe déjà.',
            'type.required' => 'Le type d\'exercice est requis.',
            'type.in' => 'Le type d\'exercice doit être Force, Cardio ou Temps.',
        ];
    }
}
