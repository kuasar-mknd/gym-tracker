<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

/**
 * A personal record only ever went up.
 *
 * update() returns early whenever the new value is not greater than the stored
 * one, and nothing anywhere lowered a record. One mistyped weight — 500 for 50
 * — therefore became that exercise's record permanently: correcting the set did
 * nothing, deleting it did nothing, and the figure stayed on the profile for
 * good.
 */
describe('personal records follow the sets they came from', function (): void {
    /**
     * The fixture every case starts from: a user, a strength exercise, and the
     * workout line its sets hang off.
     *
     * @return array{User, Exercise, WorkoutLine}
     */
    $context = function (): array {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['type' => 'strength']);
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        return [$user, $exercise, $line];
    };

    $maxWeight = fn (User $user, Exercise $exercise): ?PersonalRecord => PersonalRecord::where('user_id', $user->id)
        ->where('exercise_id', $exercise->id)
        ->where('type', 'max_weight')
        ->first();

    it('records the weight that was lifted', function () use ($context, $maxWeight): void {
        [$user, $exercise, $line] = $context();

        Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 100, 'reps' => 5, 'is_warmup' => false]);

        expect((float) $maxWeight($user, $exercise)?->value)->toBe(100.0);
    });

    it('comes back down when the typo is corrected', function () use ($context, $maxWeight): void {
        [$user, $exercise, $line] = $context();

        Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 60, 'reps' => 5, 'is_warmup' => false]);
        $typo = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 500, 'reps' => 5, 'is_warmup' => false]);

        expect((float) $maxWeight($user, $exercise)?->value)->toBe(500.0);

        $typo->update(['weight' => 50]);

        expect((float) $maxWeight($user, $exercise)?->value)->toBe(60.0);
    });

    it('comes back down when the mistaken set is deleted', function () use ($context, $maxWeight): void {
        [$user, $exercise, $line] = $context();

        Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 3, 'is_warmup' => false]);
        $typo = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 999, 'reps' => 3, 'is_warmup' => false]);

        $typo->delete();

        expect((float) $maxWeight($user, $exercise)?->value)->toBe(80.0);
    });

    it('disappears when nothing is left to hold it', function () use ($context, $maxWeight): void {
        [$user, $exercise, $line] = $context();

        $only = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 120, 'reps' => 2, 'is_warmup' => false]);

        $only->delete();

        expect($maxWeight($user, $exercise))->toBeNull();
    });

    it('leaves a record alone when the set that changed is not the one holding it', function () use ($context, $maxWeight): void {
        [$user, $exercise, $line] = $context();

        $lighter = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 40, 'reps' => 8, 'is_warmup' => false]);
        Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 150, 'reps' => 1, 'is_warmup' => false]);

        $lighter->update(['weight' => 45]);

        expect((float) $maxWeight($user, $exercise)?->value)->toBe(150.0);
    });

    it('does not congratulate the user for fixing a typo', function () use ($context): void {
        [, , $line] = $context();

        Notification::fake();

        Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 70, 'reps' => 5, 'is_warmup' => false]);
        $typo = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 400, 'reps' => 5, 'is_warmup' => false]);

        Notification::fake();
        $typo->update(['weight' => 40]);

        Notification::assertNothingSent();
    });
});

/**
 * A tie credits the session that got there first.
 *
 * `recompute()` walks the sets and keeps the best with `$value > $bestValue`.
 * Two sets at the same weight therefore leave the record on the **earlier**
 * one — which is the honest answer to "when did you set this record?". The
 * later set did not beat anything.
 *
 * Nothing asserted it, so `>` and `>=` were interchangeable: mutating the
 * comparison moved the record onto the last set and every test stayed green.
 * The figure on screen would have been identical; only the date and the session
 * it links to would have changed, which is exactly the kind of difference
 * nobody notices until they click through.
 */
it('credits the first set when two tie for the record', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['type' => 'strength']);

    $sets = collect([1, 2])->map(function (int $rang) use ($user, $exercise): Set {
        $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        return Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 5,
            'is_warmup' => false,
        ]);
    });

    app(App\Services\PersonalRecordService::class)->recompute($user, $exercise->id, ['max_weight']);

    $record = PersonalRecord::where('user_id', $user->id)
        ->where('exercise_id', $exercise->id)
        ->where('type', 'max_weight')
        ->firstOrFail();

    $premiere = $sets->firstOrFail();

    expect((float) $record->value)->toBe(100.0)
        ->and($record->set_id)->toBe($premiere->id);
});
