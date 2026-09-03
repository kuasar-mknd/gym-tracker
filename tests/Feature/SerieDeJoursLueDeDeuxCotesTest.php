<?php

declare(strict_types=1);

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\StreakService;
use Illuminate\Http\Request;

/*
 * `users.current_streak` est un compteur incrémental : il n'est touché que
 * lorsqu'une séance est enregistrée. Passé un jour sans rien faire, il continue
 * d'annoncer la valeur d'hier — la péremption n'est visible qu'à la lecture.
 *
 * `HandleInertiaRequests` faisait cette correction en ligne, `UserResource`
 * rendait la valeur brute. Le même utilisateur avait donc deux séries
 * différentes selon le canal qui demandait, et c'est le même client qui
 * emprunte les deux.
 */

it('rend zéro des deux côtés quand la série est périmée', function (): void {
    $user = User::factory()->create([
        'current_streak' => 7,
        'longest_streak' => 7,
        'last_workout_at' => now()->subDays(3),
    ]);

    // `data_get` plutot qu'un acces direct : `toArray()` est declare
    // `array<string, mixed>`, donc indexer dedans est un acces sur du `mixed`.
    $parLaRessource = data_get(new UserResource($user)->toArray(Request::create('/')), 'stats.current_streak');
    $parLeService = app(StreakService::class)->currentStreakFor($user);

    expect($parLeService)->toBe(0)
        ->and($parLaRessource)->toBe(0);
});

it('rend la série en cours des deux côtés quand elle est vivante', function (): void {
    $user = User::factory()->create([
        'current_streak' => 7,
        'longest_streak' => 7,
        'last_workout_at' => now()->subDay(),
    ]);

    $parLaRessource = data_get(new UserResource($user)->toArray(Request::create('/')), 'stats.current_streak');

    expect(app(StreakService::class)->currentStreakFor($user))->toBe(7)
        ->and($parLaRessource)->toBe(7);
});

it('rend zéro pour qui n’a jamais fait de séance', function (): void {
    $user = User::factory()->create(['current_streak' => 0, 'last_workout_at' => null]);

    expect(app(StreakService::class)->currentStreakFor($user))->toBe(0);
});
