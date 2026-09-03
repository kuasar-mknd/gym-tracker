<?php

declare(strict_types=1);

use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\Models\Exercise;
use App\Models\User;
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
 * Le controle porte sur la forme : un `distinct` ou un `group by` sur cette
 * table est le defaut lui-meme, et MySQL ne retient pas de balayage lache ici.
 */
it('ne balaie pas les lignes pour compter les exercices', function (): void {
    $user = User::factory()->create();
    semerLignesPourExercices($user, 30, 6);

    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $action = app(FetchWorkoutsIndexAction::class);
    $compte = $action->execute($user)['totalExercises'];
    $requetes = array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog());
    DB::disableQueryLog();

    expect($compte)->toBe(6);

    foreach ($requetes as $sql) {
        $minuscule = mb_strtolower($sql);

        if (! str_contains($minuscule, 'from `workout_lines`')) {
            continue;
        }

        expect($minuscule)->not->toContain('distinct')
            ->and($minuscule)->not->toContain('group by');
    }
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
