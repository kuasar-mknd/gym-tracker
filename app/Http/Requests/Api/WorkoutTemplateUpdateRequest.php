<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class WorkoutTemplateUpdateRequest extends FormRequest
{
    use \App\Http\Requests\Concerns\VerifieLesExercicesDuGabarit;

    /**
     * La requête ne vérifie que la connexion ; l'autorisation vit dans le
     * contrôleur, et son refus est rendu en 404 par bootstrap/app.php.
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
            'exercises.*.sets.*.is_warmup' => 'boolean',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(fn (\Illuminate\Validation\Validator $validator): null => $this->verifierLesExercices($validator));
    }
}
