<?php

declare(strict_types=1);

use App\Actions\Stats\GetStatsDashboardAction;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\Stats\StatsCacheManager;
use App\Services\Stats\VolumeStatsService;
use App\Services\Stats\WorkoutStatsService;
use Illuminate\Support\Facades\Cache;

/*
 * Le garde qui remplace une liste écrite à la main.
 *
 * `StatsCacheManager` énumérait les clés à oublier, période par période, et la
 * liste avait dérivé de ce que l'application écrit réellement : les entrées
 * `muscle_dist` à 90 et 365 jours, et `duration_history` à 30, n'étaient
 * JAMAIS invalidées. Un chiffre faux pouvait rester affiché une demi-heure
 * après la correction qui aurait dû le changer (#1502).
 *
 * Ce test ne récite pas la liste — il la MESURE : il fait écrire l'application,
 * relève ce qui est réellement en cache, puis exige que l'invalidation n'en
 * laisse rien. Ajouter demain une période sans l'oublier fera rougir ceci.
 */

/**
 * Les clés `stats.` presentes dans le magasin, quel que soit leur suffixe.
 *
 * @return list<string>
 */
function clesDeStatistiques(): array
{
    $magasin = Cache::store()->getStore();
    $propriete = new ReflectionProperty($magasin, 'storage');
    /** @var array<string, mixed> $contenu */
    $contenu = $propriete->getValue($magasin);

    return array_values(array_filter(
        array_keys($contenu),
        fn (string $cle): bool => str_starts_with($cle, 'stats.')
            /*
             * Sauf le jeton de version, qui n'est pas une valeur en cache mais
             * le MOYEN d'en invalider : les entrees 1RM sont indexees par
             * exercice, donc innombrables, et `clearVolumeStats()` les perime
             * en ecrivant un nouveau jeton plutot qu'en les enumerant.
             *
             * Ce garde l'a trouve des sa premiere execution, ce qui est plutot
             * bon signe pour lui.
             */
            && ! str_starts_with($cle, 'stats.1rm_version.')
    ));
}

it('n’oublie aucune clé de statistique qu’il a fait écrire', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 100, 'reps' => 10, 'is_warmup' => false]);

    Cache::flush();

    // On fait écrire l'application par ses vrais points d'entrée, sur toutes
    // les périodes qu'elle sait servir.
    $tableauDeBord = app(GetStatsDashboardAction::class);
    $volume = app(VolumeStatsService::class);
    $seances = app(WorkoutStatsService::class);
    foreach ([7, 30, 90, 365] as $jours) {
        $tableauDeBord->performanceOverview($user, $jours);
        $volume->getVolumeTrend($user, $jours);
    }
    $volume->getVolumeHistory($user, 20);
    $volume->getVolumeHistory($user, 30);
    $seances->getDurationHistory($user, 20);
    $seances->getDurationHistory($user, 30);
    $volume->getWeeklyVolumeComparison($user);

    $ecrites = clesDeStatistiques();
    expect($ecrites)->not->toBeEmpty('rien n’a été mis en cache : le test ne prouverait rien');

    app(StatsCacheManager::class)->clearWorkoutRelatedStats($user);

    $restantes = clesDeStatistiques();

    expect($restantes)->toBe([], sprintf(
        "ces clés survivent à l'invalidation : %s",
        implode(', ', $restantes)
    ));
});
