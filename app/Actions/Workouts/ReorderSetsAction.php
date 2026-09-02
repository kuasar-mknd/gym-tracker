<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\WorkoutLine;
use Illuminate\Support\Facades\DB;

class ReorderSetsAction
{
    /**
     * Renumerote les series d'un exercice depuis le rang soumis.
     *
     * En UNE requete plutot qu'une par serie : un `case` sur la clef primaire
     * rend l'ecriture independante du nombre de series, et evite qu'un ordre
     * intermediaire soit lisible entre deux mises a jour.
     *
     * @param  list<int>  $series  Les identifiants, dans l'ordre voulu.
     */
    public function execute(WorkoutLine $ligne, array $series): void
    {
        if ($series === []) {
            return;
        }

        $quand = [];
        $liaisons = [];

        foreach ($series as $rang => $id) {
            $quand[] = 'when ? then ?';
            $liaisons[] = $id;
            $liaisons[] = $rang;
        }

        DB::update(
            'update sets set `order` = case id '.implode(' ', $quand).' end, updated_at = ?
             where workout_line_id = ? and id in ('.implode(',', array_fill(0, count($series), '?')).')',
            [...$liaisons, now(), $ligne->id, ...$series]
        );
    }
}
