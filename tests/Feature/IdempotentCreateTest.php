<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

/**
 * A create the server accepted and wrote, whose response never reached the
 * client, is indistinguishable from one that never arrived — both surface as a
 * network error. SyncService queues it and replays it later, and without a key
 * naming the attempt, the replay makes a second row.
 *
 * Workout 8 in the owner's own database is the shape of it: five lines stamped
 * within two seconds of each other by a queue flush, two of them the same
 * exercise.
 */
describe('idempotent creates', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        $this->workout = Workout::factory()->create(['user_id' => $this->user->id, 'ended_at' => null]);
        $this->exercise = Exercise::factory()->create();
    });

    it('creates one line however many times the attempt is replayed', function (): void {
        $payload = ['workout_id' => $this->workout->id, 'exercise_id' => $this->exercise->id];
        $headers = ['Idempotency-Key' => 'attempt-line-1'];

        $first = $this->actingAs($this->user)->postJson(route('api.v1.workout-lines.store'), $payload, $headers);
        $second = $this->actingAs($this->user)->postJson(route('api.v1.workout-lines.store'), $payload, $headers);
        $third = $this->actingAs($this->user)->postJson(route('api.v1.workout-lines.store'), $payload, $headers);

        $first->assertSuccessful();
        $second->assertSuccessful();
        $third->assertSuccessful();

        expect($second->json('data.id'))->toBe($first->json('data.id'))
            ->and($third->json('data.id'))->toBe($first->json('data.id'))
            ->and(WorkoutLine::where('workout_id', $this->workout->id)->count())->toBe(1);
    });

    it('creates one set however many times the attempt is replayed', function (): void {
        $line = WorkoutLine::factory()->create([
            'workout_id' => $this->workout->id,
            'exercise_id' => $this->exercise->id,
        ]);
        $payload = ['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 8];
        $headers = ['Idempotency-Key' => 'attempt-set-1'];

        $first = $this->actingAs($this->user)->postJson(route('api.v1.sets.store'), $payload, $headers);
        $second = $this->actingAs($this->user)->postJson(route('api.v1.sets.store'), $payload, $headers);

        $first->assertSuccessful();
        $second->assertSuccessful();

        expect($second->json('data.id'))->toBe($first->json('data.id'))
            ->and($line->sets()->count())->toBe(1);
    });

    it('still creates a distinct row for a genuinely distinct attempt', function (): void {
        $line = WorkoutLine::factory()->create([
            'workout_id' => $this->workout->id,
            'exercise_id' => $this->exercise->id,
        ]);
        $payload = ['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 8];

        $this->actingAs($this->user)->postJson(route('api.v1.sets.store'), $payload, ['Idempotency-Key' => 'a'])
            ->assertSuccessful();
        $this->actingAs($this->user)->postJson(route('api.v1.sets.store'), $payload, ['Idempotency-Key' => 'b'])
            ->assertSuccessful();

        expect($line->sets()->count())->toBe(2);
    });

    /**
     * Three identical sets in a row is an ordinary thing to log. Without a key
     * the server has no way to tell them apart, and must not try.
     */
    it('leaves an unkeyed create alone', function (): void {
        $line = WorkoutLine::factory()->create([
            'workout_id' => $this->workout->id,
            'exercise_id' => $this->exercise->id,
        ]);
        $payload = ['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 8];

        $this->actingAs($this->user)->postJson(route('api.v1.sets.store'), $payload)->assertSuccessful();
        $this->actingAs($this->user)->postJson(route('api.v1.sets.store'), $payload)->assertSuccessful();

        expect($line->sets()->count())->toBe(2);
    });

    /**
     * The key travels in a client-controlled header. A lookup searching on it
     * globally would hand one user's row to whoever replayed their key, so the
     * lookup is scoped to the already-authorised parent.
     */
    it('never returns another user\'s row to whoever replays their key', function (): void {
        $stranger = User::factory()->create();
        $strangerWorkout = Workout::factory()->create(['user_id' => $stranger->id, 'ended_at' => null]);
        $strangerLine = WorkoutLine::factory()->create([
            'workout_id' => $strangerWorkout->id,
            'exercise_id' => $this->exercise->id,
        ]);

        $this->actingAs($stranger)->postJson(
            route('api.v1.sets.store'),
            ['workout_line_id' => $strangerLine->id, 'weight' => 200, 'reps' => 1],
            ['Idempotency-Key' => 'stolen-key'],
        )->assertSuccessful();

        $mine = WorkoutLine::factory()->create([
            'workout_id' => $this->workout->id,
            'exercise_id' => $this->exercise->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('api.v1.sets.store'),
            ['workout_line_id' => $mine->id, 'weight' => 80, 'reps' => 8],
            ['Idempotency-Key' => 'stolen-key'],
        );

        // The stranger's set must neither come back nor be disturbed.
        expect((float) $response->json('data.weight'))->toBe(80.0)
            ->and(Set::where('weight', 200)->whereBelongsTo($strangerLine, 'workoutLine')->count())->toBe(1);
    });

    it('does not expose the key in the API response', function (): void {
        $line = WorkoutLine::factory()->create([
            'workout_id' => $this->workout->id,
            'exercise_id' => $this->exercise->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('api.v1.sets.store'),
            ['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 8],
            ['Idempotency-Key' => 'attempt-hidden'],
        );

        expect($response->json('data'))->not->toHaveKey('idempotency_key');
    });
});
