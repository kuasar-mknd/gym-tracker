<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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
 * Le total se REDUIT de la somme, il ne s'accumule plus.
 *
 * Tant qu'il etait alimente par un `increment($delta)` calcule entre un SELECT
 * et un UPDATE sans transaction, deux requetes concurrentes appliquaient deux
 * fois le meme ecart et le compteur derivait pour toujours. Une valeur derivee
 * se recale d'elle-meme ; c'est ce que la passe nocturne faisait a la main.
 *
 * On simule la derive plutot que la concurrence : le resultat observable est le
 * meme, et lui est deterministe.
 */
it('se recale tout seul apres une derive', function (): void {
    $user = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

    serieValideeDe($seance, 100, 5);

    DB::table('users')->where('id', $user->id)->update(['total_volume' => 99_999]);

    serieValideeDe($seance, 50, 2);

    $somme = (float) DB::table('workouts')->where('user_id', $user->id)->sum('workout_volume');

    expect((float) $user->refresh()->total_volume)->toBe($somme)
        ->and($somme)->toBe(600.0);
});

it('compte les seances multiples du meme utilisateur', function (): void {
    $user = User::factory()->create();

    serieValideeDe(Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]), 100, 5);
    serieValideeDe(Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]), 60, 5);

    expect((float) $user->refresh()->total_volume)->toBe(800.0);
});
