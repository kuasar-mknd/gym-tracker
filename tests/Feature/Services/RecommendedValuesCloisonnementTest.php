<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\RecommendedValuesService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Deux utilisateurs sur le meme exercice : un exercice par defaut n'appartient a
 * personne, donc les deux en ont un historique dans la meme table.
 *
 * L'autre a souleve 200 kg hier, plus recemment que les 50 kg de la semaine
 * derniere : si le service oublie de se limiter au proprietaire, c'est 200 qui
 * remonte, et l'utilisateur se voit proposer une barre qu'il n'a jamais faite.
 *
 * @return array{0: User, 1: Exercise, 2: WorkoutLine} l'utilisateur, l'exercice, sa ligne du jour
 */
function ligneDuJourAvecUnAutreUtilisateurPlusRecent(): array
{
    $exercice = Exercise::factory()->create(['user_id' => null]);

    $lui = User::factory()->create();
    $laSienne = Workout::factory()->create(['user_id' => $lui->id, 'started_at' => now()->subWeek()]);
    $saLigne = WorkoutLine::factory()->create(['workout_id' => $laSienne->id, 'exercise_id' => $exercice->id]);
    Set::factory()->count(3)->create(['workout_line_id' => $saLigne->id, 'weight' => 50.0, 'reps' => 8]);

    $lautre = User::factory()->create();
    $seanceDeLautre = Workout::factory()->create(['user_id' => $lautre->id, 'started_at' => now()->subDay()]);
    $ligneDeLautre = WorkoutLine::factory()->create(['workout_id' => $seanceDeLautre->id, 'exercise_id' => $exercice->id]);
    Set::factory()->count(3)->create(['workout_line_id' => $ligneDeLautre->id, 'weight' => 200.0, 'reps' => 8]);

    $aujourdhui = Workout::factory()->create(['user_id' => $lui->id, 'started_at' => now()]);
    $ligneDuJour = WorkoutLine::factory()->create(['workout_id' => $aujourdhui->id, 'exercise_id' => $exercice->id]);

    return [$lui, $exercice, $ligneDuJour];
}

it('ne recommande que d’après l’historique du propriétaire de la séance', function (): void {
    [, , $ligneDuJour] = ligneDuJourAvecUnAutreUtilisateurPlusRecent();

    expect(app(RecommendedValuesService::class)->getRecommendedValues($ligneDuJour)['weight'])->toBe(50.0);
});

it('ne recommande que d’après l’historique du propriétaire, y compris par lot', function (): void {
    [$lui, $exercice, $ligneDuJour] = ligneDuJourAvecUnAutreUtilisateurPlusRecent();

    $valeurs = app(RecommendedValuesService::class)
        ->batchRecommendedValues(new Collection([$ligneDuJour]), $lui->id);

    expect($valeurs[$exercice->id]['weight'])->toBe(50.0);
});
