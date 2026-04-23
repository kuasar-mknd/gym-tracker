<?php

declare(strict_types=1);

use App\Models\Habit;
use App\Models\User;
use App\Policies\HabitPolicy;

beforeEach(function (): void {
    $this->policy = new HabitPolicy();
    $this->user = User::factory()->make(['id' => 1]);
    $this->otherUser = User::factory()->make(['id' => 2]);
    $this->habit = Habit::factory()->make([
        'user_id' => $this->user->id,
    ]);
});

describe('HabitPolicy', function (): void {
    it('allows any user to view any', function (): void {
        expect($this->policy->viewAny())->toBeTrue();
    });

    it('allows a user to view their own habit', function (): void {
        expect($this->policy->view($this->user, $this->habit))->toBeTrue();
    });

    it('prevents a user from viewing another user\'s habit', function (): void {
        expect($this->policy->view($this->otherUser, $this->habit))->toBeFalse();
    });

    it('allows any user to create', function (): void {
        expect($this->policy->create())->toBeTrue();
    });

    it('allows a user to update their own habit', function (): void {
        expect($this->policy->update($this->user, $this->habit))->toBeTrue();
    });

    it('prevents a user from updating another user\'s habit', function (): void {
        expect($this->policy->update($this->otherUser, $this->habit))->toBeFalse();
    });

    it('allows a user to delete their own habit', function (): void {
        expect($this->policy->delete($this->user, $this->habit))->toBeTrue();
    });

    it('prevents a user from deleting another user\'s habit', function (): void {
        expect($this->policy->delete($this->otherUser, $this->habit))->toBeFalse();
    });
});
