<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/**
 * The card sits beside "Total séances" and is labelled "Exercices", so it reads
 * as a count of entities. It was counting workout lines: every line of every
 * session, so a bench press done in forty sessions counted forty times. The
 * figure had no relation to the library's "N exercices disponibles", nor to the
 * Exercices card on the Stats page, which both count distinct exercises.
 */
it('counts distinct exercises, not one per session they appear in', function (): void {
    $user = User::factory()->create();
    $bench = Exercise::factory()->create(['user_id' => $user->id]);
    $squat = Exercise::factory()->create(['user_id' => $user->id]);

    // Two exercises, each done in three sessions: six lines, two exercises.
    collect(range(1, 3))->each(function () use ($user, $bench, $squat): void {
        $workout = Workout::factory()->create(['user_id' => $user->id]);

        foreach ([$bench, $squat] as $exercise) {
            WorkoutLine::factory()->create([
                'workout_id' => $workout->id,
                'exercise_id' => $exercise->id,
            ]);
        }
    });

    actingAs($user)
        ->get(route('workouts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('totalExercises', 2));
});

it('reads zero for someone who has never trained', function (): void {
    actingAs(User::factory()->create())
        ->get(route('workouts.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('totalExercises', 0));
});
