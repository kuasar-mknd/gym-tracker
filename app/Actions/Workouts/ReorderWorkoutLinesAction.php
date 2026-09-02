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
     * L'ordre soumis est repris en entier plutot qu'applique par echange : le
     * rang est fourni par le client a la creation (`$data['order'] ??`), et
     * l'index n'est pas unique, donc deux lignes d'une meme seance peuvent
     * partager un rang. Renumeroter depuis la liste recue les departage, ce
     * qu'un echange laisserait tel quel.
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
