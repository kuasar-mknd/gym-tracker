<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\DB;

function serieValideeDe(Workout $workout, float $poids, int $repetitions): Set
{
    $ligne = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    return Set::factory()->create([
        'workout_line_id' => $ligne->id,
        'weight' => $poids,
        'reps' => $repetitions,
        'is_completed' => true,
        'is_warmup' => false,
    ]);
}

it('tient le total de l’utilisateur egal a la somme de ses seances', function (): void {
    $user = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

    serieValideeDe($seance, 100, 5);
    serieValideeDe($seance, 80, 10);

    $somme = (float) DB::table('workouts')->where('user_id', $user->id)->sum('workout_volume');

    expect($user->volumeSouleve())->toBe($somme)
        ->and($somme)->toBe(1300.0);
});

/**
 * Le total de l'utilisateur n'est plus tenu a chaque serie : il se lit dans
 * ses seances. Sur le serveur de production, chaque ecriture coute jusqu'a une
 * seconde, et `update users` etait la plus chere des trois qu'une serie payait.
 */
it('n’écrit plus le compte de l’utilisateur quand une série est validée', function (): void {
    $user = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    serieValideeDe($seance, 100, 5);
    $surLesUtilisateurs = array_filter(
        array_map(fn (array $entree): string => mb_strtolower((string) $entree['query']), DB::getQueryLog()),
        fn (string $sql): bool => str_contains($sql, 'update `users`') || str_contains($sql, 'insert into `users`'),
    );
    DB::disableQueryLog();

    expect(array_values($surLesUtilisateurs))->toBe([])
        ->and($user->volumeSouleve())->toBe(500.0);
});

/**
 * Ce qui rendait l'accumulation fausse avant #1595 n'etait pas l'accumulation
 * mais sa forme : un SELECT puis un UPDATE, sans transaction. L'ancien total
 * est desormais relu SOUS VERROU dans la meme transaction que l'ecriture.
 */
it('n’ajoute que l’écart, sans resommer l’historique', function (): void {
    $user = User::factory()->create();

    // Neuf seances anciennes, deja comptees dans le total.
    foreach (range(1, 9) as $rang) {
        $ancienne = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => now()->subDays($rang)]);
        serieValideeDe($ancienne, 10, 10);
    }

    expect($user->volumeSouleve())->toBe(900.0);

    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    serieValideeDe($seance, 20, 5);
    $requetes = array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog());
    DB::disableQueryLog();

    expect($user->volumeSouleve())->toBe(1000.0);

    foreach ($requetes as $sql) {
        expect($sql)->not->toContain('sum(workout_volume)');
    }
});

it('compte les seances multiples du meme utilisateur', function (): void {
    $user = User::factory()->create();

    serieValideeDe(Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]), 100, 5);
    serieValideeDe(Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]), 60, 5);

    expect($user->volumeSouleve())->toBe(800.0);
});
