<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Services\StatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function lecturesStats(callable $geste): int
{
    $releve = function (): int {
        $total = 0;
        /** @var list<object{Value: string}> $compteurs */
        $compteurs = DB::select("show session status like 'Handler_read%'");

        foreach ($compteurs as $compteur) {
            $total += (int) $compteur->Value;
        }

        return $total;
    };

    $avant = $releve();
    $geste();

    return $releve() - $avant;
}

function semerSeances(User $user, int $combien, int $depuisJours, Exercise $exercice): void
{
    $seances = [];

    for ($rang = 0; $rang < $combien; $rang++) {
        $quand = Carbon::now()->subDays($depuisJours + $rang);
        $seances[] = ['user_id' => $user->id, 'name' => 'S'.$depuisJours.'-'.$rang, 'started_at' => $quand, 'ended_at' => $quand->copy()->addHour(), 'workout_volume' => 1000, 'created_at' => $quand, 'updated_at' => now()];
    }

    foreach (array_chunk($seances, 500) as $lot) {
        DB::table('workouts')->insert($lot);
    }

    $lignes = [];

    foreach (DB::table('workouts')->where('user_id', $user->id)->whereNull('created_at')->get() as $ignore) {
        // jamais atteint : place pour la lisibilite du bloc suivant
    }

    foreach (DB::table('workouts')->where('user_id', $user->id)->get(['id', 'started_at']) as $seance) {
        if (DB::table('workout_lines')->where('workout_id', $seance->id)->exists()) {
            continue;
        }

        $lignes[] = ['workout_id' => $seance->id, 'user_id' => $user->id, 'workout_started_at' => $seance->started_at, 'exercise_id' => $exercice->id, 'order' => 0, 'created_at' => now(), 'updated_at' => now()];
    }

    foreach (array_chunk($lignes, 500) as $lot) {
        DB::table('workout_lines')->insert($lot);
    }

    $series = [];

    foreach (DB::table('workout_lines')->where('user_id', $user->id)->pluck('id') as $ligneId) {
        if (DB::table('sets')->where('workout_line_id', $ligneId)->exists()) {
            continue;
        }

        $series[] = ['workout_line_id' => $ligneId, 'weight' => 50, 'reps' => 10, 'is_completed' => true, 'is_warmup' => false, 'created_at' => now(), 'updated_at' => now()];
    }

    foreach (array_chunk($series, 500) as $lot) {
        DB::table('sets')->insert($lot);
    }
}

/**
 * La repartition musculaire annonce trente jours et lisait toute la base.
 *
 * Jointe a `exercises` et groupee sur `exercises.category`, MySQL attaquait par
 * le CATALOGUE — son index `(user_id, category, name)` rend le groupe deja
 * trie, ce qu'il prefere a tout le reste — puis tirait toutes les lignes de
 * chaque exercice, tous utilisateurs confondus. La borne de trente jours ne
 * s'appliquait qu'a la fin, ligne par ligne. Mesure aux compteurs
 * `Handler_read_*` : 706 lectures, puis 2 506 apres neuf cents seances
 * ANCIENNES, hors fenetre, sans effet possible sur le resultat.
 *
 * Le controle porte sur la FORME et non sur le compte de lectures : celui-ci
 * depend des statistiques que MySQL tient par table, que les tests voisins font
 * bouger. Un temoin qui passe seul et tombe dans la suite est instable, et un
 * temoin instable ne vaut rien.
 */
it('n’attire pas le catalogue ni les séances dans l’agrégation', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => null, 'category' => 'Pectoraux']);
    semerSeances($user, 3, 0, $exercice);

    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(StatsService::class)->getMuscleDistribution($user, 30);
    $requetes = array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog());
    DB::disableQueryLog();

    $agregations = array_values(array_filter(
        $requetes,
        fn (string $sql): bool => str_contains(mb_strtolower($sql), 'sum(')
    ));

    expect($agregations)->toHaveCount(1);

    // Ni le catalogue — qui detournait le plan — ni `workouts`, dont la copie
    // denormalisee porte desormais le proprietaire et la date.
    expect($agregations[0])->not->toContain('`exercises`')
        ->and($agregations[0])->not->toContain('`workouts`')
        ->and($agregations[0])->toContain('`workout_lines`.`workout_started_at`');
});

it('répartit le volume de la fenêtre par catégorie', function (): void {
    $user = User::factory()->create();
    $pectoraux = Exercise::factory()->create(['user_id' => null, 'category' => 'Pectoraux']);
    $dos = Exercise::factory()->create(['user_id' => null, 'category' => 'Dos']);

    semerSeances($user, 2, 0, $pectoraux);
    semerSeances($user, 3, 10, $dos);

    $repartition = app(StatsService::class)->getMuscleDistribution($user, 30);
    $parCategorie = [];

    foreach ($repartition as $stat) {
        $parCategorie[$stat->category] = $stat->volume;
    }

    // Une serie de 50 kg × 10 par seance : 500 par seance.
    expect($parCategorie)->toBe(['Pectoraux' => 1000.0, 'Dos' => 1500.0]);
});

it('ignore les séances hors de la fenêtre', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => null, 'category' => 'Pectoraux']);

    semerSeances($user, 1, 0, $exercice);
    semerSeances($user, 5, 100, $exercice);

    $repartition = app(StatsService::class)->getMuscleDistribution($user, 30);

    expect($repartition)->toHaveCount(1)
        ->and($repartition[0]->volume)->toBe(500.0);
});
