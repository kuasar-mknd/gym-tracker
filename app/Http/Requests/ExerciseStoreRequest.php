<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExerciseStoreRequest extends FormRequest
{
    /**
     * L'autorisation vit dans le contrôleur ; le refus sur une ressource
     * d'autrui, validation comprise, est rendu en 404 par bootstrap/app.php.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('exercises')->where(fn (Builder $query) => $query->where(function (Builder $q): void {
                    $q->whereNull('user_id')
                        ->orWhere('user_id', $this->user()?->id);
                })),
            ],
            'type' => ['required', Rule::in(['strength', 'cardio', 'timed'])],
            'category' => ['nullable', Rule::enum(\App\Enums\ExerciseCategory::class)],
        ];
    }

    /**
     * Get custom messages for validation errors.
     *
     * @return array<string, string>
     */
    #[\Override]
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
