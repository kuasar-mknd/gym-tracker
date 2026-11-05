<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Services\AchievementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Une séance faite il y a tant de jours, à 10 h, écrite par les modèles : sa
 * série cochée, écrite après sa fin, recalcule la série de jours (#2171).
 */
function seanceIlYA(User $user, int $recul): Workout
{
    return Workout::factory()->faite()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays($recul)->setTime(10, 0),
    ]);
}

/**
 * L'application affichait « record : 1 jour » a qui portait deja le trophee des
 * trois jours.
 *
 * L'avance d'un cran (`updateStreak()`, retiré par #2171) ne savait
 * qu'avancer depuis `last_workout_at`, et ne pouvait donc pas voir qu'une
 * seance saisie apres coup venait de combler un trou. `recalculerDepuisLesFaits()`
 * existait et repartait des faits, mais n'etait branche que sur la SUPPRESSION.
 *
 * Le moteur de succes, lui, repart des faits a chaque synchronisation : les
 * deux chemins repondaient donc differemment a la meme question.
 */
it('compte une série remplie après coup', function (): void {
    $user = User::factory()->create();

    // Aujourd'hui, puis les deux jours precedents saisis apres coup.
    seanceIlYA($user, 0);
    seanceIlYA($user, 2);
    seanceIlYA($user, 1);

    expect(User::findOrFail($user->id)->longest_streak)->toBe(3);
});

it('accorde la colonne et le moteur de succès', function (): void {
    $user = User::factory()->create();

    foreach ([0, 5, 4, 3] as $recul) {
        seanceIlYA($user, $recul);
    }

    $succes = app(AchievementService::class);
    $reflet = new ReflectionClass(AchievementService::class);
    $dates = $reflet->getMethod('getUniqueWorkoutDates')->invoke($succes, $user->refresh());
    $calculee = $reflet->getMethod('calculateMaxStreak')->invoke($succes, $dates);

    expect(User::findOrFail($user->id)->longest_streak)->toBe($calculee);
});

/**
 * `DISTINCT DATE(started_at)` appliquait une fonction a une colonne indexee :
 * l'index ne rendait plus les lignes deja ordonnees, d'ou `Using temporary;
 * Using filesort` au plan. Mesure aux compteurs `Handler_read_*`, sur un chemin
 * que chaque enregistrement de seance emprunte tant qu'un succes de serie reste
 * verrouille : 98 lectures a 40 seances, 418 a 200 — contre 57 et 217 une fois
 * la colonne lue nue.
 *
 * Le controle porte sur la forme : le nombre de lectures depend des
 * statistiques que MySQL tient par table, que les tests voisins font bouger.
 */
it('lit la colonne nue, sans fonction ni dédoublonnage en SQL', function (): void {
    $user = User::factory()->create();
    seanceIlYA($user, 0);

    $succes = app(AchievementService::class);
    $methode = new ReflectionClass(AchievementService::class)->getMethod('getUniqueWorkoutDates');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $methode->invoke($succes, $user->refresh());
    $requetes = array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog());
    DB::disableQueryLog();

    $lectures = array_values(array_filter(
        $requetes,
        fn (string $sql): bool => str_contains($sql, 'from `workouts`')
    ));

    expect($lectures)->toHaveCount(1);

    $sql = mb_strtolower($lectures[0]);

    expect($sql)->not->toContain('date(')
        ->and($sql)->not->toContain('distinct')
        ->and($sql)->toContain('order by `started_at` desc');
});

it('rend les jours distincts du plus récent au plus ancien', function (): void {
    $user = User::factory()->create();

    foreach ([2, 1, 1, 0] as $recul) {
        seanceIlYA($user, $recul);
    }

    $succes = app(AchievementService::class);
    $dates = new ReflectionClass(AchievementService::class)
        ->getMethod('getUniqueWorkoutDates')
        ->invoke($succes, $user->refresh());

    expect($dates)->toBe([
        Carbon::now()->toDateString(),
        Carbon::now()->subDay()->toDateString(),
        Carbon::now()->subDays(2)->toDateString(),
    ]);
});
