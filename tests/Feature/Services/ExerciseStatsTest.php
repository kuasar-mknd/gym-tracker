<?php

declare(strict_types=1);

use App\DTOs\Stats\MuscleDistributionStat;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\Stats\ExerciseStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
 * `ExerciseStatsService` etait la classe la moins tenue du depot : 27,27 %, trois
 * mutants sur quatre survivants. Ses deux methodes alimentent le camembert des
 * groupes musculaires et la courbe de 1RM estime — deux chiffres que l'ecran
 * affiche et que rien ne verifiait.
 */

/**
 * Une serie sur un exercice d'une categorie donnee, a une date donnee.
 */
function serieDeCategorie(User $user, string $categorie, float $poids, int $repetitions, ?Carbon $quand = null): Exercise
{
    $exercise = Exercise::factory()->create([
        'user_id' => $user->id,
        'type' => 'strength',
        'category' => $categorie,
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => $quand ?? now()->subDays(2),
    ]);

    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => $poids,
        'reps' => $repetitions,
        'is_warmup' => false,
    ]);

    return $exercise;
}

it('répartit le volume par groupe musculaire', function (): void {
    $user = User::factory()->create();

    serieDeCategorie($user, 'Pectoraux', 100, 10);   // 1000
    serieDeCategorie($user, 'Dos', 50, 10);          // 500

    $repartition = app(ExerciseStatsService::class)->getMuscleDistribution($user->refresh());

    $parCategorie = collect($repartition)
        ->mapWithKeys(fn (MuscleDistributionStat $stat): array => [$stat->category => $stat->volume])
        ->all();

    // Trie par cle : la requete n'ordonne pas, donc l'ordre des parts est celui
    // que rend la base. C'est le contenu qui est le contrat, pas la sequence.
    ksort($parCategorie);

    expect($parCategorie)->toBe(['Dos' => 500.0, 'Pectoraux' => 1000.0]);
});

/**
 * La fenetre de la repartition, des deux cotes.
 *
 * Trente jours par defaut : une seance du trentieme jour compte, une du
 * trente-et-unieme non. Les deux mutants qui deplacaient cette borne d'un jour
 * survivaient.
 */
it('ne compte que les séances de la fenêtre', function (): void {
    $user = User::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    serieDeCategorie($user, 'Jambes', 100, 10, now()->subDays(29));
    serieDeCategorie($user, 'Épaules', 100, 10, now()->subDays(31));

    $repartition = app(ExerciseStatsService::class)->getMuscleDistribution($user->refresh());

    expect(array_map(fn (MuscleDistributionStat $stat): string => $stat->category, $repartition))
        ->toBe(['Jambes']);

    Carbon::setTestNow();
});

/**
 * Le 1RM estime suit la formule d'Epley : poids x (1 + repetitions / 30).
 *
 * C'est le chiffre que la courbe de progression affiche, et rien ne le
 * verifiait. 100 kg pour 10 repetitions valent 133,33 — pas 100, pas 110.
 */
it('estime le 1RM par la formule d’Epley', function (): void {
    $user = User::factory()->create();

    $exercise = serieDeCategorie($user, 'Pectoraux', 100, 10);

    $progression = app(ExerciseStatsService::class)
        ->getExercise1RMProgress($user->refresh(), $exercise->id);

    expect($progression)->toHaveCount(1)
        ->and(round($progression[0]->one_rep_max, 2))->toBe(133.33);
});

/**
 * Les deux dates du point de courbe, dont celle que le front lit vraiment.
 *
 * `full_date` alimente l'axe et le regroupement cote client ; `date` est
 * l'etiquette affichee. Les replis sur « ?? » qui les remplacaient en cas
 * d'echec d'analyse ne pouvaient pas se declencher — `started_at` est NOT NULL —
 * et six mutants y survivaient.
 */
it('date chaque point de la courbe de 1RM', function (): void {
    $user = User::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $exercise = serieDeCategorie($user, 'Pectoraux', 80, 5, Carbon::parse('2026-06-03 09:30:00'));

    $progression = app(ExerciseStatsService::class)
        ->getExercise1RMProgress($user->refresh(), $exercise->id);

    expect($progression[0]->date)->toBe('03/06')
        ->and($progression[0]->full_date)->toBe('2026-06-03');

    Carbon::setTestNow();
});

/**
 * Sans version en cache, la clé retombe sur « 1 ».
 *
 * La version n'existe qu'une fois les statistiques invalidées une première fois.
 * Avant cela, la clé est absente et le repli sert vraiment — c'est lui qui rend
 * la clé de cache calculable pour un compte neuf.
 */
it('calcule la clé de 1RM sans version enregistrée', function (): void {
    $user = User::factory()->create();

    $exercise = serieDeCategorie($user, 'Pectoraux', 100, 10);

    Cache::forget("stats.1rm_version.{$user->id}");

    app(ExerciseStatsService::class)->getExercise1RMProgress($user->refresh(), $exercise->id);

    expect(Cache::has("stats.1rm.{$user->id}.{$exercise->id}.90.v1"))->toBeTrue();
});

it('garde chaque statistique d’exercice en cache trente minutes', function (string $methode, string $cle): void {
    $user = User::factory()->create();

    $maintenant = Carbon::parse('2026-06-15 12:00:00');
    Carbon::setTestNow($maintenant);

    $exercise = serieDeCategorie($user, 'Pectoraux', 100, 10);

    Cache::forget("stats.1rm_version.{$user->id}");

    app(ExerciseStatsService::class)->{$methode}($user->refresh(), ...($methode === 'getMuscleDistribution' ? [] : [$exercise->id]));

    $cleComplete = str_replace(['{id}', '{ex}'], [(string) $user->id, (string) $exercise->id], $cle);

    expect(Cache::has($cleComplete))->toBeTrue('la clé attendue n’a pas été écrite');

    Carbon::setTestNow($maintenant->copy()->addMinutes(30)->subSecond());
    expect(Cache::has($cleComplete))->toBeTrue('l’entrée a expiré trop tôt');

    Carbon::setTestNow($maintenant->copy()->addMinutes(30)->addSecond());
    expect(Cache::has($cleComplete))->toBeFalse('l’entrée a survécu au-delà de trente minutes');

    Carbon::setTestNow();
})->with([
    'répartition musculaire' => ['getMuscleDistribution', 'stats.muscle_dist.{id}.30'],
    'progression de 1RM' => ['getExercise1RMProgress', 'stats.1rm.{id}.{ex}.90.v1'],
]);
