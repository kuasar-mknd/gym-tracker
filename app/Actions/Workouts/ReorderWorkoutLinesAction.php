<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Workout;
use Illuminate\Support\Facades\DB;

class ReorderWorkoutLinesAction
{
    /**
     * Renumerote les lignes d'une seance depuis le rang soumis.
     *
     * En UNE requete plutot qu'une par ligne : un `case` sur la clef primaire
     * rend l'ecriture independante du nombre d'exercices, et evite qu'un ordre
     * intermediaire soit lisible entre deux mises a jour.
     *
     * Les seances anciennes portent toutes `order = 0` — la colonne est
     * `NOT NULL DEFAULT 0` et rien ne l'a renseignee avant que le
     * reordonnancement n'existe. Elles sont donc normalisees ici, au premier
     * deplacement, et non par une migration qui toucherait des donnees que
     * personne n'a demande a changer.
     *
     * @param  list<int>  $lignes  Les identifiants, dans l'ordre voulu.
     */
    public function execute(Workout $seance, array $lignes): void
    {
        if ($lignes === []) {
            return;
        }

        $quand = [];
        $liaisons = [];

        foreach ($lignes as $rang => $id) {
            $quand[] = 'when ? then ?';
            $liaisons[] = $id;
            $liaisons[] = $rang;
        }

        DB::update(
            'update workout_lines set `order` = case id '.implode(' ', $quand).' end, updated_at = ?
             where workout_id = ? and id in ('.implode(',', array_fill(0, count($lignes), '?')).')',
            [...$liaisons, now(), $seance->id, ...$lignes]
        );
    }
}
