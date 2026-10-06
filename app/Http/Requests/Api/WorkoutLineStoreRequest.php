<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\WorkoutLine;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;

class WorkoutLineStoreRequest extends FormRequest
{
    /**
     * La requête ne vérifie que la connexion ; l'autorisation vit dans le
     * contrôleur, et son refus est rendu en 404 par bootstrap/app.php.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Le rang d'une ligne va de zéro à `WorkoutLine::RANG_MAX` : au-delà de
     * la colonne (int), la base refusait l'écriture et la requête finissait
     * en 500.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workout_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('workouts', 'id')->where(function (Builder $query): void {
                    $query->where('user_id', $this->user()?->id);
                }),
            ],
            'exercise_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('exercises', 'id')->where(function (Builder $query): void {
                    $query->where(function (Builder $q): void {
                        $q->whereNull('user_id')
                            ->orWhere('user_id', $this->user()?->id);
                    });
                }),
            ],
            'order' => 'nullable|integer|min:0|max:'.WorkoutLine::RANG_MAX,
            'notes' => 'nullable|string|max:1000',
        ];
    }
}
