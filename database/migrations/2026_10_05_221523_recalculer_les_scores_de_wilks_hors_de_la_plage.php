<?php

declare(strict_types=1);

use App\Actions\Tools\CreateWilksScoreAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recalcule les scores de Wilks enregistrés avec un poids de corps hors de la
 * plage de la formule (#1959).
 *
 * Le polynôme n'est défini que de 40 à 201,9 kg pour un homme et de 26,51 à
 * 154,53 kg pour une femme. Il s'appliquait sans borne : au-delà, le score
 * enregistré pouvait être négatif ou aberrant, et l'historique comme les deux
 * graphiques de la page Wilks lisent la colonne telle quelle. Le poids est
 * désormais ramené à la borne la plus proche ; les entrées sont conservées
 * (poids de corps, total, genre, unité), ces scores se recalculent donc avec
 * le même calcul borné que `CreateWilksScoreAction`.
 *
 * Un score est un fait daté, écrit une fois : seules les lignes dont le poids
 * converti en kilos sort de la plage changent, et les autres restent telles
 * qu'elles ont été enregistrées.
 *
 * Rejouable : elle assigne un score calculé, sans rien ajuster.
 */
return new class() extends Migration
{
    public function up(): void
    {
        DB::table('wilks_scores')
            ->select(['id', 'body_weight', 'lifted_weight', 'gender', 'unit', 'score'])
            ->chunkById(500, function (Collection $lignes): void {
                foreach ($lignes as $ligne) {
                    if (! is_object($ligne) || ! is_numeric($ligne->body_weight) || ! is_numeric($ligne->lifted_weight)) {
                        continue;
                    }

                    $poidsDeCorps = (float) $ligne->body_weight;
                    $genre = is_string($ligne->gender) ? $ligne->gender : '';
                    $unite = is_string($ligne->unit) ? $ligne->unit : 'kg';

                    if (! CreateWilksScoreAction::poidsDeCorpsHorsDeLaPlage($poidsDeCorps, $genre, $unite)) {
                        continue;
                    }

                    DB::table('wilks_scores')
                        ->where('id', $ligne->id)
                        ->update(['score' => CreateWilksScoreAction::score($poidsDeCorps, (float) $ligne->lifted_weight, $genre, $unite)]);
                }
            });
    }

    /**
     * Volontairement vide : revenir en arrière rendrait des scores faux, et
     * rien n'a gardé lesquels.
     */
    public function down(): void
    {
    }
};
