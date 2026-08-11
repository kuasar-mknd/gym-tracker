<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

/**
 * One exercise carrying one set, and the volume it should account for.
 *
 * @return array{0: WorkoutLine, 1: float}
 */
function lineWithVolume(Workout $workout, Exercise $exercise, float $weight, int $reps): array
{
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    Set::factory()->create(['workout_line_id' => $line->id, 'weight' => $weight, 'reps' => $reps]);

    return [$line, $weight * $reps];
}

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * users.total_volume and workouts.workout_volume are maintained by model events
 * on Set. sets.workout_line_id is ON DELETE CASCADE, so when a line or a whole
 * workout goes, the database removes those rows itself and Eloquent never hears
 * about it: Set::deleted does not fire and the volume stays in the counters
 * forever.
 *
 * Every exercise ever removed from a session has been inflating a lifetime
 * total that the user sees on their dashboard.
 */
describe('volume counters survive a cascade', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create(['total_volume' => 0]);
        $this->workout = Workout::factory()->create([
            'user_id' => $this->user->id,
            'ended_at' => null,
            'workout_volume' => 0,
        ]);
        $this->exercise = Exercise::factory()->create();
    });

    it('counts the volume of a set as it is added', function (): void {
        [, $volume] = lineWithVolume($this->workout, $this->exercise, 100, 10);

        expect((float) $this->user->fresh()->total_volume)->toBe($volume)
            ->and((float) $this->workout->fresh()->workout_volume)->toBe($volume);
    });

    it('gives the volume back when the exercise is removed', function (): void {
        [$line] = lineWithVolume($this->workout, $this->exercise, 100, 10);

        $line->delete();

        expect((float) $this->user->fresh()->total_volume)->toBe(0.0)
            ->and((float) $this->workout->fresh()->workout_volume)->toBe(0.0);
    });

    it('leaves the other exercises of the session alone', function (): void {
        [$removed] = lineWithVolume($this->workout, $this->exercise, 100, 10);
        [, $keptVolume] = lineWithVolume($this->workout, $this->exercise, 50, 4);

        $removed->delete();

        expect((float) $this->user->fresh()->total_volume)->toBe($keptVolume)
            ->and((float) $this->workout->fresh()->workout_volume)->toBe($keptVolume);
    });

    it('gives the volume back when the whole session is deleted', function (): void {
        lineWithVolume($this->workout, $this->exercise, 100, 10);
        lineWithVolume($this->workout, $this->exercise, 60, 5);

        $this->workout->delete();

        expect((float) $this->user->fresh()->total_volume)->toBe(0.0);
    });

    it('does not double-count when the sets were removed first', function (): void {
        [$line] = lineWithVolume($this->workout, $this->exercise, 100, 10);

        $line->sets()->each(fn (Set $set) => $set->delete());
        $line->delete();

        expect((float) $this->user->fresh()->total_volume)->toBe(0.0)
            ->and((float) $this->workout->fresh()->workout_volume)->toBe(0.0);
    });

    it('ignores a set with no weight or reps', function (): void {
        $line = WorkoutLine::factory()->create([
            'workout_id' => $this->workout->id,
            'exercise_id' => $this->exercise->id,
        ]);
        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => null,
            'reps' => null,
            'duration_seconds' => 60,
        ]);

        $line->delete();

        expect((float) $this->user->fresh()->total_volume)->toBe(0.0);
    });
});

/**
 * The counters used to accumulate a delta per save: the set's new volume minus
 * the volume it had when the model was loaded. Two requests touching the same
 * row at once — which is exactly what the per-field debounce produces when a
 * user corrects a weight and a rep count together — each computed their delta
 * from the same snapshot. Both were applied, and the totals drifted away from
 * the sets they describe, permanently.
 */
describe('volume counters survive concurrent edits', function (): void {
    it('matches the sets after two writes computed from the same snapshot', function (): void {
        $user = User::factory()->create(['total_volume' => 0]);
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'ended_at' => null,
            'workout_volume' => 0,
        ]);
        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => Exercise::factory()->create()->id,
        ]);

        Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 5]);

        // Two instances of the same row, both loaded before either is written —
        // the shape of two PATCHes arriving together.
        $asWeightRequestSeesIt = Set::find($line->sets()->first()->id);
        $asRepsRequestSeesIt = Set::find($line->sets()->first()->id);

        $asWeightRequestSeesIt->update(['weight' => 100]);
        $asRepsRequestSeesIt->update(['reps' => 8]);

        // The row is now 100 x 8. Anything else is drift.
        expect((float) $workout->fresh()->workout_volume)->toBe(800.0)
            ->and((float) $user->fresh()->total_volume)->toBe(800.0);
    });
});
