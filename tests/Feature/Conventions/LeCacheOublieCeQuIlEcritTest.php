<?php

declare(strict_types=1);

use App\Actions\Stats\GetStatsDashboardAction;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\Stats\ClesDeStats;
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

/**
 * Un compte avec une séance terminée, une ligne et une série, pour que chaque
 * lecteur ait de quoi écrire.
 */
function compteAvecSeancePourLesStatistiques(): User
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 100, 'reps' => 10, 'is_warmup' => false]);

    return $user;
}

/**
 * Fait écrire l'application par ses vrais points d'entrée, sur toutes les
 * périodes qu'elle sait servir.
 */
function faireEcrireLesStatistiquesDe(User $user): void
{
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
}

it('n’oublie aucune clé de statistique qu’il a fait écrire', function (): void {
    $user = compteAvecSeancePourLesStatistiques();

    Cache::flush();

    faireEcrireLesStatistiquesDe($user);

    $ecrites = clesDeStatistiques();
    expect($ecrites)->not->toBeEmpty('rien n’a été mis en cache : le test ne prouverait rien');

    app(StatsCacheManager::class)->clearWorkoutRelatedStats($user);
    $avantRelecture = clesDeStatistiques();

    // Les lecteurs rejouent : chacun doit écrire une clef neuve. Un lecteur
    // qui n'a pas pris la version retomberait sur son ancienne entrée et
    // n'en écrirait aucune.
    faireEcrireLesStatistiquesDe($user);

    $nouvelles = array_values(array_diff(clesDeStatistiques(), $avantRelecture));
    expect(count($nouvelles))->toBe(count($ecrites), sprintf(
        "%d clés écrites avant l'invalidation, %d clés neuves après : un lecteur relit une ancienne entrée",
        count($ecrites),
        count($nouvelles)
    ));
});

/*
 * La version elle-même vit dans le cache, sans échéance, et Redis l'évince
 * comme le reste quand il manque de place. Un compteur évincé repartait de
 * zéro et remontait au numéro déjà servi : après une invalidation, chaque
 * lecteur retombait sur son entrée d'avant, et n'en écrivait aucune (#2151).
 */
it('n’oublie aucune clé de statistique qu’il a fait écrire quand la version des séances a été évincée', function (): void {
    $user = compteAvecSeancePourLesStatistiques();
    $version = "stats-version.seances.{$user->id}";

    Cache::flush();

    faireEcrireLesStatistiquesDe($user);
    $ecrites = count(clesDeStatistiques());
    app(StatsCacheManager::class)->clearWorkoutRelatedStats($user);
    faireEcrireLesStatistiquesDe($user);

    expect(Cache::has($version))->toBeTrue('la version n’est pas en cache : l’effacer ne prouverait rien');
    Cache::forget($version);
    app()->forgetScopedInstances();

    app(StatsCacheManager::class)->clearWorkoutRelatedStats($user);
    $avantRelecture = clesDeStatistiques();
    faireEcrireLesStatistiquesDe($user);

    $nouvelles = array_values(array_diff(clesDeStatistiques(), $avantRelecture));
    expect(count($nouvelles))->toBe($ecrites, sprintf(
        "%d clés écrites avant l'éviction, %d clés neuves après : un lecteur relit une entrée d'avant l'invalidation",
        $ecrites,
        count($nouvelles)
    ));
});

/*
 * Une séance écrite ou supprimée hors des actions de l'application, comme le
 * fait le panneau, ne passait par aucun gestionnaire : `Workout::booted()`
 * renouvelle seul les versions, et le compte peut ne plus exister quand il le
 * fait.
 */
it('n’oublie aucune clé de statistique qu’il a fait écrire quand une séance est supprimée sans le gestionnaire', function (): void {
    $user = compteAvecSeancePourLesStatistiques();

    Cache::flush();

    faireEcrireLesStatistiquesDe($user);
    $ecrites = count(clesDeStatistiques());
    $avantRelecture = clesDeStatistiques();

    $user->workouts()->firstOrFail()->delete();

    faireEcrireLesStatistiquesDe($user);

    $nouvelles = array_values(array_diff(clesDeStatistiques(), $avantRelecture));
    expect(count($nouvelles))->toBe($ecrites, sprintf(
        "%d clés écrites avant la suppression, %d clés neuves après : un lecteur relit une entrée d'avant la suppression",
        $ecrites,
        count($nouvelles)
    ));
});

it('renouvelle la version des séances d’un compte par son identifiant, compte disparu compris', function (): void {
    $user = User::factory()->create();
    $avant = ClesDeStats::seances($user, 'sonde');
    $identifiant = $user->id;

    $user->delete();
    ClesDeStats::invaliderSeancesDuCompte($identifiant);

    expect(ClesDeStats::seances($user, 'sonde'))->not->toBe($avant);
});
