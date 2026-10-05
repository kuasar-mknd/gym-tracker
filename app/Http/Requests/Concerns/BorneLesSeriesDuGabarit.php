<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Set;
use App\Models\WorkoutTemplate;

/**
 * Les bornes des exercices et des séries d'un modèle de séance, communes à sa
 * création et à sa modification.
 *
 * Le nombre de séries par exercice n'avait pas de plafond, ni les répétitions
 * et le poids de borne : une seule requête écrivait autant de lignes que son
 * corps en portait, et une valeur hors de la colonne finissait en 500. Les
 * séries d'un modèle deviennent celles de la séance qui en démarre : elles
 * prennent les bornes d'une série de séance (`Set`), sous la capacité de
 * `workout_template_sets` comme de `sets`. Écrites une fois ici, les deux
 * requêtes ne peuvent pas diverger.
 */
trait BorneLesSeriesDuGabarit
{
    /**
     * @return array<string, string>
     */
    protected function reglesDesSeriesDuGabarit(): array
    {
        return [
            'exercises' => 'nullable|array|max:'.WorkoutTemplate::EXERCICES_MAX,
            'exercises.*.sets' => 'nullable|array|max:'.WorkoutTemplate::SERIES_MAX_PAR_EXERCICE,
            'exercises.*.sets.*.reps' => 'nullable|integer|min:0|max:'.Set::REPETITIONS_MAX,
            'exercises.*.sets.*.weight' => 'nullable|numeric|min:0|max:'.Set::POIDS_MAX_KG,
        ];
    }
}
