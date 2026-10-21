<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Services\ActiveWorkoutService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

/*
 * La séance en cours porte sa clé d'idempotence (#2049), et le cache change
 * de clé avec sa forme : une entrée de deux heures écrite avant le
 * déploiement, sous l'ancienne clé, n'est pas relue.
 */
it('rend la clé de la séance en cours, sans relire une entrée de l’ancienne forme', function (): void {
    $user = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null, 'idempotency_key' => '4a5b6c7d-2049-4e8f-9a0b-0000000000a9']);
    Cache::put("user_active_workout_{$user->id}", ['seance' => null], now()->addHours(2));

    $enCours = app(ActiveWorkoutService::class)->for($user);

    expect($enCours?->id)->toBe($seance->id)
        ->and($enCours?->idempotency_key)->toBe('4a5b6c7d-2049-4e8f-9a0b-0000000000a9');
});
