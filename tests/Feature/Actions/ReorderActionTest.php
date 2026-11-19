<?php

declare(strict_types=1);

use App\Actions\Workouts\ReorderAction;
use App\Models\Set;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Validation\ValidationException;

/*
 * L'action ne s'en remet pas à la règle de la requête : une chaîne numérique
 * qui n'est pas un entier (« 12.9 ») ne vaut pas l'identifiant 12.
 */

it('refuse une chaine numerique non entiere au lieu de la tronquer', function (): void {
    $ligne = WorkoutLine::factory()->create(['workout_id' => Workout::factory()->create()->id]);
    $premiere = Set::factory()->create(['workout_line_id' => $ligne->id]);
    $seconde = Set::factory()->create(['workout_line_id' => $ligne->id]);

    expect(fn () => app(ReorderAction::class)->execute(
        $ligne->sets(),
        [$seconde->id, $premiere->id.'.9'],
        'sets',
    ))->toThrow(ValidationException::class);
});

it('accepte les identifiants entiers, en nombre ou en chaine', function (): void {
    $ligne = WorkoutLine::factory()->create(['workout_id' => Workout::factory()->create()->id]);
    $premiere = Set::factory()->create(['workout_line_id' => $ligne->id, 'order' => 0]);
    $seconde = Set::factory()->create(['workout_line_id' => $ligne->id, 'order' => 1]);

    app(ReorderAction::class)->execute($ligne->sets(), [(string) $seconde->id, $premiere->id], 'sets');

    expect($seconde->refresh()->order)->toBe(0)
        ->and($premiere->refresh()->order)->toBe(1);
});

it('refuse ce qui n est ni un nombre ni une chaine', function (): void {
    $ligne = WorkoutLine::factory()->create(['workout_id' => Workout::factory()->create()->id]);
    Set::factory()->create(['workout_line_id' => $ligne->id]);

    expect(fn () => app(ReorderAction::class)->execute($ligne->sets(), [true], 'sets'))
        ->toThrow(ValidationException::class);
});
