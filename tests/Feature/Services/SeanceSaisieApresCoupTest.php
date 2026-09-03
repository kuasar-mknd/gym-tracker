<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\RecommendedValuesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

function ligneAvecSerie(User $user, Exercise $exercice, string $quand, float $poids): WorkoutLine
{
    $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::parse($quand)]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => $poids, 'reps' => 8, 'is_completed' => true]);

    return $ligne;
}

/**
 * « La derniere fois » se lit sur la DATE, pas sur l'ordre d'insertion.
 *
 * Les deux chemins du service repondaient differemment : l'unitaire triait par
 * `workouts.started_at`, le lot prenait `MAX(workout_lines.id)`. Une seance
 * saisie apres coup — celle d'avant-hier, enregistree ce soir — porte
 * l'identifiant le plus grand tout en etant la plus ancienne : les deux
 * designaient alors des lignes differentes, et l'utilisateur voyait une
 * recommandation dependre du chemin emprunte.
 */
it('recommande d’après la séance la plus récente, pas la dernière saisie', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    // La vraie derniere fois : 100 kg il y a trois jours.
    ligneAvecSerie($user, $exercice, '2026-06-10 18:00:00', 100);

    // Saisie APRES, mais datee d'avant : 60 kg il y a dix jours.
    ligneAvecSerie($user, $exercice, '2026-06-03 18:00:00', 60);

    $courante = Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::parse('2026-06-13 18:00:00')]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $courante->id, 'exercise_id' => $exercice->id]);

    $service = app(RecommendedValuesService::class);

    /*
     * Le cache est vide avant CHAQUE appel.
     *
     * Les deux chemins partagent la cle `recommended_values:{user}:v{version}:{exercice}:{seance}` :
     * sans ce vidage, le lot relit ce que l'unitaire vient d'ecrire et
     * n'execute jamais sa propre requete. Le test passait alors des deux cotes
     * du correctif, en ne testant qu'un seul chemin.
     */
    Cache::flush();
    $unitaire = $service->getRecommendedValues(WorkoutLine::with('workout')->findOrFail($ligne->id));

    Cache::flush();
    $lot = $service->batchRecommendedValues(WorkoutLine::whereKey($ligne->id)->with('workout')->get(), $user->id);

    expect($unitaire['weight'])->toBe(100.0)
        ->and($lot[$exercice->id]['weight'] ?? null)->toBe(100.0);
});
