<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\WorkoutLine;
use Spatie\Activitylog\Models\Activity;

/**
 * WorkoutLine declared getActivitylogOptions() without using the LogsActivity
 * trait, so the options were never read and a session's exercises went
 * unaudited while the workout around them was logged. These cover the write
 * path the trait turns on; remove it and all three fail.
 *
 * Scoped to the subject, because creating a line creates a Workout and an
 * Exercise through the factories, and both of those are logged too.
 *
 * @return \Illuminate\Database\Eloquent\Builder<\Spatie\Activitylog\Models\Activity>
 */
function activitiesFor(WorkoutLine $line): \Illuminate\Database\Eloquent\Builder
{
    return Activity::query()
        ->where('subject_type', $line->getMorphClass())
        ->where('subject_id', $line->getKey());
}

it('records a workout line change in the column v5 reads', function (): void {
    $line = WorkoutLine::factory()->create(['order' => 1, 'notes' => null]);

    $line->update(['order' => 2, 'notes' => 'Dernière série en échec']);

    $activity = activitiesFor($line)->where('event', 'updated')->sole();

    expect($activity->attribute_changes['attributes'])->toMatchArray([
        'order' => 2,
        'notes' => 'Dernière série en échec',
    ])->and($activity->attribute_changes['old'])->toMatchArray([
        'order' => 1,
        'notes' => null,
    ]);
});

/**
 * logOnly() names 'exercise.name', not a column — the only entry in this app's
 * options that resolves through a relation rather than off the model.
 */
it('resolves the exercise name through the relation when logging', function (): void {
    $exercise = Exercise::factory()->create(['name' => 'Développé couché']);

    $line = WorkoutLine::factory()->create(['exercise_id' => $exercise->id]);

    $activity = activitiesFor($line)->where('event', 'created')->sole();

    expect($activity->attribute_changes['attributes']['exercise.name'])->toBe('Développé couché');
});

/**
 * logOnlyDirty + dontLogEmptyChanges: idempotency_key is outside logOnly, so
 * writing it fires `updated` with nothing to record and must leave no row.
 */
it('does not log a change to an attribute outside logOnly', function (): void {
    $line = WorkoutLine::factory()->create();

    // Anchored on the create, so this still fails if logging stops altogether.
    expect(activitiesFor($line)->count())->toBe(1);

    $line->idempotency_key = 'replayed-create-attempt';
    $line->save();

    expect(activitiesFor($line)->count())->toBe(1);
});
