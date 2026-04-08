<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Habit;
use App\Models\User;
use App\Policies\HabitPolicy;

describe('viewAny', function () {
    it('allows any user to view any models', function () {
        $policy = new HabitPolicy();

        expect($policy->viewAny())->toBeTrue();
    });
});

describe('view', function () {
    it('allows the owner to view the habit', function () {
        $user = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $user->id]);
        $policy = new HabitPolicy();

        expect($policy->view($user, $habit))->toBeTrue();
    });

    it('denies a non-owner from viewing the habit', function () {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $owner->id]);
        $policy = new HabitPolicy();

        expect($policy->view($otherUser, $habit))->toBeFalse();
    });
});

describe('create', function () {
    it('allows any user to create models', function () {
        $policy = new HabitPolicy();

        expect($policy->create())->toBeTrue();
    });
});

describe('update', function () {
    it('allows the owner to update the habit', function () {
        $user = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $user->id]);
        $policy = new HabitPolicy();

        expect($policy->update($user, $habit))->toBeTrue();
    });

    it('denies a non-owner from updating the habit', function () {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $owner->id]);
        $policy = new HabitPolicy();

        expect($policy->update($otherUser, $habit))->toBeFalse();
    });
});

describe('delete', function () {
    it('allows the owner to delete the habit', function () {
        $user = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $user->id]);
        $policy = new HabitPolicy();

        expect($policy->delete($user, $habit))->toBeTrue();
    });

    it('denies a non-owner from deleting the habit', function () {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $owner->id]);
        $policy = new HabitPolicy();

        expect($policy->delete($otherUser, $habit))->toBeFalse();
    });
});
