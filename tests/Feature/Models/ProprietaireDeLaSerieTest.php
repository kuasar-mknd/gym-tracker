<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * `sets.user_id` copie le compte de la ligne ; la ligne déjà en mémoire
 * épargne une lecture, mais seulement si c'est celle que la clef nomme (#2325).
 */

function proprietaireDeLaSerieLigneDuCompte(User $compte): WorkoutLine
{
    return WorkoutLine::factory()->create(['workout_id' => Workout::factory()->create(['user_id' => $compte->id])->id]);
}

it('copie le compte de la ligne en mémoire à la création, sans relire la ligne', function (): void {
    $compte = User::factory()->create();
    $ligne = proprietaireDeLaSerieLigneDuCompte($compte);

    $lectures = 0;
    DB::listen(function (QueryExecuted $requete) use (&$lectures): void {
        if (str_starts_with($requete->sql, 'select `user_id` from `workout_lines`')) {
            $lectures++;
        }
    });

    $serie = $ligne->sets()->make(['weight' => 50, 'reps' => 5]);
    $serie->setRelation('workoutLine', $ligne);
    $serie->save();

    expect($serie->fresh()?->user_id)->toBe($compte->id)
        ->and($lectures)->toBe(0);
});

it('relit le compte quand la clef change malgré la ligne gardée en mémoire', function (): void {
    $ancien = User::factory()->create();
    $nouveau = User::factory()->create();
    $ligne = proprietaireDeLaSerieLigneDuCompte($ancien);
    $ligneDuNouveau = proprietaireDeLaSerieLigneDuCompte($nouveau);

    $serie = Set::factory()->create(['workout_line_id' => $ligne->id]);
    $serie->setRelation('workoutLine', $ligne);

    $serie->workout_line_id = $ligneDuNouveau->id;
    $serie->save();

    expect($serie->fresh()?->user_id)->toBe($nouveau->id);
});
