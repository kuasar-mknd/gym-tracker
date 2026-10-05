<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Set;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetStoreRequest extends FormRequest
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
     * Les valeurs d'une série sont bornées par les plafonds métier de `Set`,
     * sous la capacité de leurs colonnes : au-delà, la base refusait
     * l'écriture et la requête finissait en 500, que la file hors ligne prend
     * pour une erreur passagère à réessayer, au lieu d'un refus lisible.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workout_line_id' => [
                'required',
                Rule::exists('workout_lines', 'id')->where(function (Builder $query): void {
                    /** @var \Illuminate\Database\Query\Builder $query */
                    $query->whereIn('workout_id', function (Builder $q): void {
                        /** @var \Illuminate\Database\Query\Builder $q */
                        $q->select('id')
                            ->from('workouts')
                            ->where('user_id', $this->user()?->id);
                    });
                }),
            ],
            'weight' => 'nullable|numeric|min:0|max:'.Set::POIDS_MAX_KG,
            'reps' => 'nullable|integer|min:0|max:'.Set::REPETITIONS_MAX,
            'duration_seconds' => 'nullable|integer|min:0|max:'.Set::DUREE_MAX_SECONDES,
            'distance_km' => 'nullable|numeric|min:0|max:'.Set::DISTANCE_MAX_KM,
            'is_warmup' => 'boolean',
            'is_completed' => 'boolean',
        ];
    }

    /**
     * Une serie postee sans `is_completed` est une serie FAITE.
     *
     * La colonne vaut 0 par defaut en base, et l'interface web l'exploite : elle
     * cree chaque ligne decochee, puis l'utilisateur la coche une fois la serie
     * executee. Elle envoie donc `false` explicitement, et ce faux-la est
     * respecte.
     *
     * Un client d'API qui poste un poids et des repetitions, lui, rapporte ce
     * qu'il vient de faire. En retombant sur le defaut de la colonne, sa serie
     * ne comptait dans AUCUN volume depuis #1499 — ni celui de la seance, ni
     * celui de l'utilisateur — alors qu'elle posait quand meme un record. Deux
     * reponses contraires a la meme question.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        if (! $this->has('is_completed')) {
            $this->merge(['is_completed' => true]);
        }
    }
}
