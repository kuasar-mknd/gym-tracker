<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Policies\WorkoutLinePolicy;

describe('WorkoutLinePolicy', function (): void {
    describe('viewAny', function (): void {
        it('allows any user to view any workout lines', function (): void {
            $policy = new WorkoutLinePolicy();
            $user = User::factory()->make();

            expect($policy->viewAny())->toBeTrue();
        });
    });

    describe('view', function (): void {
        it('allows the user to view their own workout line', function (): void {
            $user = User::factory()->make(['id' => 1]);
            $workout = Workout::factory()->make(['user_id' => 1]);
            $workoutLine = WorkoutLine::factory()->make();
            $workoutLine->setRelation('workout', $workout);

            $policy = new WorkoutLinePolicy();

            expect($policy->view($user, $workoutLine))->toBeTrue();
        });

        it('denies the user from viewing someone else\'s workout line', function (): void {
            $user = User::factory()->make(['id' => 1]);
            $workout = Workout::factory()->make(['user_id' => 2]);
            $workoutLine = WorkoutLine::factory()->make();
            $workoutLine->setRelation('workout', $workout);

            $policy = new WorkoutLinePolicy();

            expect($policy->view($user, $workoutLine))->toBeFalse();
        });
    });

    describe('create', function (): void {
        it('allows the user to create a workout line if no workout is provided', function (): void {
            $user = User::factory()->make();
            $policy = new WorkoutLinePolicy();

            expect($policy->create($user))->toBeTrue();
        });

        it('allows the user to create a workout line if it belongs to them and is not ended', function (): void {
            $user = User::factory()->make(['id' => 1]);
            $workout = Workout::factory()->make(['user_id' => 1, 'ended_at' => null]);
            $policy = new WorkoutLinePolicy();

            expect($policy->create($user, $workout))->toBeTrue();
        });

        it('denies the user from creating a workout line if the workout does not belong to them', function (): void {
            $user = User::factory()->make(['id' => 1]);
            $workout = Workout::factory()->make(['user_id' => 2, 'ended_at' => null]);
            $policy = new WorkoutLinePolicy();

            expect($policy->create($user, $workout))->toBeFalse();
        });

        it('denies the user from creating a workout line if the workout has ended', function (): void {
            $user = User::factory()->make(['id' => 1]);
            $workout = Workout::factory()->make(['user_id' => 1, 'ended_at' => now()]);
            $policy = new WorkoutLinePolicy();

            expect($policy->create($user, $workout))->toBeFalse();
        });
    });

    describe('update', function (): void {
        it('allows the user to update a workout line if it belongs to them and the workout is not ended', function (): void {
            $user = User::factory()->make(['id' => 1]);
            $workout = Workout::factory()->make(['user_id' => 1, 'ended_at' => null]);
            $workoutLine = WorkoutLine::factory()->make();
            $workoutLine->setRelation('workout', $workout);

            $policy = new WorkoutLinePolicy();

            expect($policy->update($user, $workoutLine))->toBeTrue();
        });

        it('denies the user from updating a workout line if the workout does not belong to them', function (): void {
            $user = User::factory()->make(['id' => 1]);
            $workout = Workout::factory()->make(['user_id' => 2, 'ended_at' => null]);
            $workoutLine = WorkoutLine::factory()->make();
            $workoutLine->setRelation('workout', $workout);

            $policy = new WorkoutLinePolicy();

            expect($policy->update($user, $workoutLine))->toBeFalse();
        });

        it('denies the user from updating a workout line if the workout has ended', function (): void {
            $user = User::factory()->make(['id' => 1]);
            $workout = Workout::factory()->make(['user_id' => 1, 'ended_at' => now()]);
            $workoutLine = WorkoutLine::factory()->make();
            $workoutLine->setRelation('workout', $workout);

            $policy = new WorkoutLinePolicy();

            expect($policy->update($user, $workoutLine))->toBeFalse();
        });
    });

    describe('delete', function (): void {
        it('allows the user to delete a workout line if it belongs to them and the workout is not ended', function (): void {
            $user = User::factory()->make(['id' => 1]);
            $workout = Workout::factory()->make(['user_id' => 1, 'ended_at' => null]);
            $workoutLine = WorkoutLine::factory()->make();
            $workoutLine->setRelation('workout', $workout);

            $policy = new WorkoutLinePolicy();

            expect($policy->delete($user, $workoutLine))->toBeTrue();
        });

        it('denies the user from deleting a workout line if the workout does not belong to them', function (): void {
            $user = User::factory()->make(['id' => 1]);
            $workout = Workout::factory()->make(['user_id' => 2, 'ended_at' => null]);
            $workoutLine = WorkoutLine::factory()->make();
            $workoutLine->setRelation('workout', $workout);

            $policy = new WorkoutLinePolicy();

            expect($policy->delete($user, $workoutLine))->toBeFalse();
        });

        it('denies the user from deleting a workout line if the workout has ended', function (): void {
            $user = User::factory()->make(['id' => 1]);
            $workout = Workout::factory()->make(['user_id' => 1, 'ended_at' => now()]);
            $workoutLine = WorkoutLine::factory()->make();
            $workoutLine->setRelation('workout', $workout);

            $policy = new WorkoutLinePolicy();

            expect($policy->delete($user, $workoutLine))->toBeFalse();
        });
    });
});
