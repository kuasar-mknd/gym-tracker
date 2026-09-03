<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Services\ActiveWorkoutService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('designe la plus recente de celles qui ne sont pas terminees', function (): void {
    $user = User::factory()->create();

    // Terminee : la plus recente de toutes, et pourtant pas la bonne reponse.
    Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-06-18 11:00:00'),
        'ended_at' => Carbon::parse('2026-06-18 11:45:00'),
    ]);

    Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-06-16 09:00:00'),
        'ended_at' => null,
    ]);

    $attendue = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-06-18 10:00:00'),
        'ended_at' => null,
    ]);

    $trouvee = app(ActiveWorkoutService::class)->for($user);

    expect($trouvee?->id)->toBe($attendue->id)
        ->and($trouvee?->workout_lines_count)->toBe(0);
});

/**
 * L'ABSENCE doit etre mise en cache, et c'est tout l'enjeu.
 *
 * `Cache::remember()` ne sert le cache que si la valeur n'est pas nulle. Un
 * `null` stocke etant indiscernable d'une absence, la requete repartait a
 * chaque requete web — dans le cas le plus courant, celui ou aucune seance
 * n'est ouverte.
 */
it('ne repose pas la question quand la reponse est qu’il n’y a rien', function (): void {
    $user = User::factory()->create();
    $service = app(ActiveWorkoutService::class);

    expect($service->for($user))->toBeNull();

    DB::flushQueryLog();
    DB::enableQueryLog();
    expect($service->for($user))->toBeNull();
    $requetes = DB::getQueryLog();
    DB::disableQueryLog();

    expect($requetes)->toBeEmpty();
});

it('repose la question une fois la seance ouverte', function (): void {
    $user = User::factory()->create();
    $service = app(ActiveWorkoutService::class);

    expect($service->for($user))->toBeNull();

    $ouverte = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

    expect($service->for($user)?->id)->toBe($ouverte->id);
});
