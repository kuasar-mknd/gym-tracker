<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

/*
 * Deux appareils qui ajoutent une série en même temps calculent le même rang
 * (`max('order') + 1`, hors verrou : #2293). Rien n'interdit ce doublon, donc
 * chaque lecture doit le départager, par la clé primaire, et toujours dans le
 * même sens.
 */
it('lit dans l’ordre de création deux séries de même rang, quel que soit l’ordre d’insertion en base', function (): void {
    $compte = User::factory()->create();
    $ligne = WorkoutLine::factory()->create([
        'workout_id' => Workout::factory()->create(['user_id' => $compte->id])->id,
    ]);
    $premiere = Set::factory()->create(['workout_line_id' => $ligne->id, 'order' => 1]);
    $seconde = Set::factory()->create(['workout_line_id' => $ligne->id, 'order' => 1]);

    expect($ligne->sets()->pluck('id')->all())->toBe([$premiere->id, $seconde->id])
        ->and($ligne->fresh()->load('sets')->sets->pluck('id')->all())->toBe([$premiere->id, $seconde->id]);
});
