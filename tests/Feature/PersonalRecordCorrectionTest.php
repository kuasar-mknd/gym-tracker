<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        $this->exercise = Exercise::factory()->create(['type' => 'strength']);
        $workout = Workout::factory()->create(['user_id' => $this->user->id, 'ended_at' => null]);
        $this->line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $this->exercise->id,
        ]);
    });

    $maxWeight = fn (User $user, Exercise $exercise): ?PersonalRecord => PersonalRecord::where('user_id', $user->id)
        ->where('exercise_id', $exercise->id)
        ->where('type', 'max_weight')
        ->first();

    it('records the weight that was lifted', function () use ($maxWeight): void {
        Set::factory()->create(['workout_line_id' => $this->line->id, 'weight' => 100, 'reps' => 5, 'is_warmup' => false]);

        expect((float) $maxWeight($this->user, $this->exercise)?->value)->toBe(100.0);
    });

    it('comes back down when the typo is corrected', function () use ($maxWeight): void {
        Set::factory()->create(['workout_line_id' => $this->line->id, 'weight' => 60, 'reps' => 5, 'is_warmup' => false]);
        $typo = Set::factory()->create(['workout_line_id' => $this->line->id, 'weight' => 500, 'reps' => 5, 'is_warmup' => false]);

        expect((float) $maxWeight($this->user, $this->exercise)?->value)->toBe(500.0);

        $typo->update(['weight' => 50]);

        expect((float) $maxWeight($this->user, $this->exercise)?->value)->toBe(60.0);
    });

    it('comes back down when the mistaken set is deleted', function () use ($maxWeight): void {
        Set::factory()->create(['workout_line_id' => $this->line->id, 'weight' => 80, 'reps' => 3, 'is_warmup' => false]);
        $typo = Set::factory()->create(['workout_line_id' => $this->line->id, 'weight' => 999, 'reps' => 3, 'is_warmup' => false]);

        $typo->delete();

        expect((float) $maxWeight($this->user, $this->exercise)?->value)->toBe(80.0);
    });

    it('disappears when nothing is left to hold it', function () use ($maxWeight): void {
        $only = Set::factory()->create(['workout_line_id' => $this->line->id, 'weight' => 120, 'reps' => 2, 'is_warmup' => false]);

        $only->delete();

        expect($maxWeight($this->user, $this->exercise))->toBeNull();
    });

    it('leaves a record alone when the set that changed is not the one holding it', function () use ($maxWeight): void {
        $lighter = Set::factory()->create(['workout_line_id' => $this->line->id, 'weight' => 40, 'reps' => 8, 'is_warmup' => false]);
        Set::factory()->create(['workout_line_id' => $this->line->id, 'weight' => 150, 'reps' => 1, 'is_warmup' => false]);

        $lighter->update(['weight' => 45]);

        expect((float) $maxWeight($this->user, $this->exercise)?->value)->toBe(150.0);
    });

    it('does not congratulate the user for fixing a typo', function (): void {
        Notification::fake();

        Set::factory()->create(['workout_line_id' => $this->line->id, 'weight' => 70, 'reps' => 5, 'is_warmup' => false]);
        $typo = Set::factory()->create(['workout_line_id' => $this->line->id, 'weight' => 400, 'reps' => 5, 'is_warmup' => false]);

        Notification::fake();
        $typo->update(['weight' => 40]);

        Notification::assertNothingSent();
    });
});
