<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Services\ActiveWorkoutService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('garde la séance en cours deux heures, pas une de plus ni de moins', function (): void {
    Carbon::setTestNow('2026-11-10 12:00:00');
    $user = User::factory()->create();
    Workout::factory()->create(['user_id' => $user->id, 'started_at' => now(), 'ended_at' => null]);

    app(ActiveWorkoutService::class)->for($user);
    $cle = "user_active_workout_v3_{$user->id}";

    Carbon::setTestNow('2026-11-10 13:30:00');
    expect(Cache::has($cle))->toBeTrue();

    Carbon::setTestNow('2026-11-10 14:30:00');
    expect(Cache::has($cle))->toBeFalse();
});

it('ne rend une séance que si l’enveloppe en cache en porte une', function (mixed $enveloppe): void {
    $user = User::factory()->create();
    Cache::put("user_active_workout_v3_{$user->id}", $enveloppe, 600);

    expect(app(ActiveWorkoutService::class)->for($user))->toBeNull();
})->with([
    'un texte au lieu de l’enveloppe' => ['pas une enveloppe'],
    'une enveloppe sans séance' => [[]],
    'une enveloppe dont la séance n’en est pas une' => [['seance' => 'pas une séance']],
    'une enveloppe dont la séance est un tableau' => [['seance' => ['id' => 1]]],
]);

it('rend la séance que porte l’enveloppe', function (): void {
    $user = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    Cache::put("user_active_workout_v3_{$user->id}", ['seance' => $seance], 600);

    expect(app(ActiveWorkoutService::class)->for($user)?->id)->toBe($seance->id);
});
