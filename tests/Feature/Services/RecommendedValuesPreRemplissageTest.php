<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\RecommendedValuesService;

/**
 * Semer un exercice fait il y a une semaine a 50 kg, puis une seule serie hier,
 * et rendre la ligne du jour dont on demandera la recommandation.
 *
 * Le 50 kg d'il y a une semaine est le temoin : si la serie d'hier est reconnue
 * comme un pre-remplissage, elle est ignoree et c'est lui qui remonte ; sinon
 * c'est la serie d'hier qui dicte la recommandation. Les deux reponses sont
 * donc toujours distinguables.
 *
 * @param  array<string, mixed>  $serieDHier  les colonnes de l'unique serie de la seance d'hier
 */
function ligneDuJourApresUneSerieDHier(array $serieDHier): WorkoutLine
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create();

    $ancienne = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subWeek()]);
    $ligneAncienne = WorkoutLine::factory()->create(['workout_id' => $ancienne->id, 'exercise_id' => $exercice->id]);
    Set::factory()->count(3)->create([
        'workout_line_id' => $ligneAncienne->id,
        'weight' => 50.0,
        'reps' => 10,
        'distance_km' => 0.0,
        'duration_seconds' => 0,
    ]);

    $hier = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDay()]);
    $ligneHier = WorkoutLine::factory()->create(['workout_id' => $hier->id, 'exercise_id' => $exercice->id]);
    Set::factory()->create($serieDHier + ['workout_line_id' => $ligneHier->id]);

    $aujourdhui = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);

    return WorkoutLine::factory()->create(['workout_id' => $aujourdhui->id, 'exercise_id' => $exercice->id]);
}

it('ignore une série validée sans qu’aucun poids n’ait été saisi', function (): void {
    $ligne = ligneDuJourApresUneSerieDHier([
        'weight' => null,
        'reps' => 10,
        'distance_km' => 0.0,
        'duration_seconds' => 0,
    ]);

    // Sans poids saisi, la serie ne dit rien de l'exercice : la recommandation
    // doit remonter au 50 kg d'il y a une semaine, et surtout pas proposer 0.
    expect(app(RecommendedValuesService::class)->getRecommendedValues($ligne)['weight'])->toBe(50.0);
});

it('ignore une série laissée aux trente secondes que l’écran pré-remplit', function (): void {
    $ligne = ligneDuJourApresUneSerieDHier([
        'weight' => 0.0,
        'reps' => 10,
        'distance_km' => 0.0,
        'duration_seconds' => 30,
    ]);

    // 0 kg, 10 repetitions, 0 km, 30 s : exactement ce que l'ecran ajoute.
    // Une duree de 30 s doit etre lue comme du pre-remplissage au meme titre
    // qu'une duree restee vide.
    expect(app(RecommendedValuesService::class)->getRecommendedValues($ligne)['weight'])->toBe(50.0);
});

it('garde une série de course : une distance saisie n’est pas un pré-remplissage', function (): void {
    $ligne = ligneDuJourApresUneSerieDHier([
        'weight' => 0.0,
        'reps' => 10,
        'distance_km' => 5.0,
        'duration_seconds' => 0,
    ]);

    // Cinq kilometres, sans avoir touche aux repetitions : c'est un historique,
    // et la recommandation doit le reproposer.
    expect(app(RecommendedValuesService::class)->getRecommendedValues($ligne))
        ->toMatchArray(['weight' => 0.0, 'distance_km' => 5.0]);
});

it('garde une série chronométrée : une durée saisie n’est pas un pré-remplissage', function (): void {
    $ligne = ligneDuJourApresUneSerieDHier([
        'weight' => 0.0,
        'reps' => 10,
        'distance_km' => 0.0,
        'duration_seconds' => 120,
    ]);

    // Deux minutes de gainage : la duree saisie fait de la serie un historique.
    expect(app(RecommendedValuesService::class)->getRecommendedValues($ligne))
        ->toMatchArray(['weight' => 0.0, 'duration_seconds' => 120]);
});
