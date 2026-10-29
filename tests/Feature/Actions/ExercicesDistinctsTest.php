<?php

declare(strict_types=1);

use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\Models\Exercise;
use App\Models\User;
use App\Services\Stats\ClesDeStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function semerLignesPourExercices(User $user, int $seances, int $combienExercices): void
{
    $exercices = Exercise::factory()->count($combienExercices)->create(['user_id' => null]);
    $lignesSeances = [];

    for ($rang = 0; $rang < $seances; $rang++) {
        $quand = Carbon::now()->subDays($rang);
        $lignesSeances[] = ['user_id' => $user->id, 'name' => 'S'.$rang, 'started_at' => $quand, 'ended_at' => $quand->copy()->addHour(), 'workout_volume' => 10, 'created_at' => $quand, 'updated_at' => now()];
    }

    foreach (array_chunk($lignesSeances, 500) as $lot) {
        DB::table('workouts')->insert($lot);
    }

    $lignes = [];

    foreach (DB::table('workouts')->where('user_id', $user->id)->get(['id', 'started_at']) as $seance) {
        foreach ($exercices as $rang => $exercice) {
            $lignes[] = ['workout_id' => $seance->id, 'user_id' => $user->id, 'workout_started_at' => $seance->started_at, 'exercise_id' => $exercice->id, 'order' => $rang, 'created_at' => now(), 'updated_at' => now()];
        }
    }

    foreach (array_chunk($lignes, 500) as $lot) {
        DB::table('workout_lines')->insert($lot);
    }
}

/**
 * « Combien d'exercices differents ai-je faits » ne depend que de la
 * BIBLIOTHEQUE de l'utilisateur, pas de son historique. `distinct()->count()`
 * lisait pourtant toutes les lignes de toutes les seances — 321 lectures
 * d'index a 40 seances, 1 601 a 200, pour le meme chiffre.
 *
 * Ce test refusait tout `distinct` sur cette table, tenu pour le defaut
 * lui-meme. La mesure dit autre chose (#2106, MySQL 8.4 et 9.7.2) : MySQL
 * peut sauter dans l'index d'un exercice au suivant (« Using index for
 * group-by ») quand les colonnes du `distinct` forment le PREFIXE de
 * `(user_id, exercise_id, workout_started_at)`, et seulement alors.
 * `count(distinct exercise_id)` n'en est pas un, et lit chaque ligne du
 * compte ; `count(distinct user_id, exercise_id)` en est un : 83 entrees a
 * quatre-vingts exercices et 9 000 lignes quand MySQL saute (E + 3), contre
 * 9 001.
 *
 * Le controle porte sur la forme ecrite, que les statistiques de la table ne
 * font pas varier : le plan, lui, est un choix de cout, qu'elles font varier
 * dans la base des tests comme ailleurs (`.ai/rules/services.md`, piege 5).
 */
it('compte les exercices sur le préfixe de l’index, que MySQL peut parcourir par sauts', function (): void {
    $user = User::factory()->create();
    semerLignesPourExercices($user, 30, 6);

    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $action = app(FetchWorkoutsIndexAction::class);
    $compte = $action->execute($user)['totalExercises'];
    $requetes = array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog());
    DB::disableQueryLog();

    $comptesDistincts = array_values(array_filter(
        $requetes,
        fn (string $sql): bool => str_contains($sql, 'from `workout_lines`') && str_contains(mb_strtolower($sql), 'distinct'),
    ));

    expect($compte)->toBe(6)
        ->and($comptesDistincts)->toHaveCount(1, implode("\n", $requetes));
    expect($comptesDistincts[0])->toContain('count(distinct `user_id`, `exercise_id`)');
});

it('compte les exercices sans les répétitions', function (): void {
    $user = User::factory()->create();
    semerLignesPourExercices($user, 12, 4);

    Cache::flush();
    $action = app(FetchWorkoutsIndexAction::class);

    expect($action->execute($user)['totalExercises'])->toBe(4);
});

it('rend zéro pour un compte sans aucune ligne', function (): void {
    $user = User::factory()->create();

    Cache::flush();
    $action = app(FetchWorkoutsIndexAction::class);

    expect($action->execute($user)['totalExercises'])->toBe(0);
});

/**
 * Le compte se garde une heure, et le cache de la production ne le rend pas
 * toujours en entier : `RedisStore` range un nombre sans le sérialiser et le
 * relit tel quel, en chaîne, là où le cache `array` des tests rend l'entier.
 * Le `(int)` de `getTotalExercises()` le ramène au type que son retour exige
 * sous `strict_types` ; sans lui, chaque lecture du cache lèverait une erreur
 * de type (#2106).
 */
it('rend un entier quand le cache rend le compte en chaîne, comme Redis', function (): void {
    $user = User::factory()->create();

    Cache::flush();
    Cache::put(ClesDeStats::seances($user, 'total_exercises'), '6', now()->addHour());
    $action = app(FetchWorkoutsIndexAction::class);

    expect($action->execute($user)['totalExercises'])->toBe(6);
});
