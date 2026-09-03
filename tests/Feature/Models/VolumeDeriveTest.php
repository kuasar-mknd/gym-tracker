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

    expect((float) $user->refresh()->total_volume)->toBe($somme)
        ->and($somme)->toBe(1300.0);
});

/**
 * Le total se recale a la FIN de la seance.
 *
 * Il l'etait a chaque serie, en resommant toutes les seances du compte : exact,
 * mais proportionnel a l'historique sur le chemin d'ecriture le plus chaud —
 * 202 des 208 lectures d'index de `recomputeVolume()` a 200 seances. Chaque
 * serie applique desormais son ECART, et la resomme n'a lieu qu'une fois, quand
 * la seance se termine.
 *
 * La propriete de recalage est donc conservee, a un moment defini : une derive
 * dure au plus une seance, contre une nuit avant qu'elle existe.
 *
 * On simule la derive plutot que la concurrence : le resultat observable est le
 * meme, et lui est deterministe.
 */
it('se recale a la fin de la seance apres une derive', function (): void {
    $user = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

    serieValideeDe($seance, 100, 5);

    DB::table('users')->where('id', $user->id)->update(['total_volume' => 99_999]);

    serieValideeDe($seance, 50, 2);

    // Toujours faux tant que la seance dure : l'ecart ne corrige pas une valeur
    // qu'il n'a pas produite.
    expect((float) $user->refresh()->total_volume)->toBe(100_099.0);

    $seance->update(['ended_at' => now()]);

    $somme = (float) DB::table('workouts')->where('user_id', $user->id)->sum('workout_volume');

    expect((float) $user->refresh()->total_volume)->toBe($somme)
        ->and($somme)->toBe(600.0);
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

    expect((float) $user->refresh()->total_volume)->toBe(900.0);

    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    serieValideeDe($seance, 20, 5);
    $requetes = array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog());
    DB::disableQueryLog();

    expect((float) $user->refresh()->total_volume)->toBe(1000.0);

    foreach ($requetes as $sql) {
        expect($sql)->not->toContain('sum(workout_volume)');
    }
});

it('compte les seances multiples du meme utilisateur', function (): void {
    $user = User::factory()->create();

    serieValideeDe(Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]), 100, 5);
    serieValideeDe(Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]), 60, 5);

    expect((float) $user->refresh()->total_volume)->toBe(800.0);
});
