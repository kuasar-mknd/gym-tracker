<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

/*
 * Une série cochée pendant qu'une fin de séance se joue compte dans la série
 * de jours (#2171, #2325) : la fin a recalculé sans elle, et l'écouteur de la
 * série relit la fin en base au lieu de se fier à la séance chargée avant
 * l'écriture.
 */

/**
 * @return array{0: User, 1: Workout, 2: WorkoutLine}
 */
function serieCocheePendantUneFinSeance(): array
{
    config(['app.env' => 'local']);
    Queue::fake();

    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now()->subHour(), 'ended_at' => null]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => Exercise::factory()->create(['user_id' => $compte->id])->id]);

    return [$compte, $seance, $ligne];
}

function serieCocheePendantUneFinTerminer(Workout $seance): Closure
{
    return function () use ($seance): void {
        Workout::query()->findOrFail($seance->id)->update(['ended_at' => now()]);
    };
}

it('compte dans la série de jours la série cochée par PATCH pendant qu une fin se joue', function (): void {
    [$compte, $seance, $ligne] = serieCocheePendantUneFinSeance();
    $serie = Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 80, 'reps' => 5, 'is_completed' => false]);

    Set::updating(serieCocheePendantUneFinTerminer($seance));

    actingAs($compte, 'sanctum')->patchJson(route('api.v1.sets.update', $serie), ['is_completed' => true])->assertOk();

    expect($compte->fresh()?->current_streak)->toBe(1);
});

it('compte dans la série de jours la série cochée à la création pendant qu une fin se joue', function (): void {
    [$compte, $seance, $ligne] = serieCocheePendantUneFinSeance();

    Set::creating(serieCocheePendantUneFinTerminer($seance));

    actingAs($compte, 'sanctum')
        ->postJson(route('api.v1.sets.store'), ['workout_line_id' => $ligne->id, 'weight' => 80, 'reps' => 5, 'is_completed' => true])
        ->assertCreated();

    expect($compte->fresh()?->current_streak)->toBe(1);
});
