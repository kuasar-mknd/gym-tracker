<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkoutTemplateRequest extends FormRequest
{
    use \App\Http\Requests\Concerns\VerifieLesExercicesDuGabarit;

    /**
     * Determine if the user is authorized to make this request.
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
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'exercises' => 'nullable|array|max:50',
            // L'existence et l'appartenance sont verifiees en une requete dans
            // `withValidator()`, pas une par element.
            'exercises.*.id' => ['required', 'integer'],
            'exercises.*.sets' => 'nullable|array',
            'exercises.*.sets.*.reps' => 'nullable|integer',
            'exercises.*.sets.*.weight' => 'nullable|numeric',
            'exercises.*.sets.*.is_warmup' => 'nullable|boolean',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(fn (\Illuminate\Validation\Validator $validator): null => $this->verifierLesExercices($validator));
    }
}
