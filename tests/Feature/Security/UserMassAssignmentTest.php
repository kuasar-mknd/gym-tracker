<?php

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;

test('current_streak cannot be mass assigned', function () {
    $this->expectException(MassAssignmentException::class);

    User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'current_streak' => 100,
    ]);
});

test('longest_streak cannot be mass assigned', function () {
    $this->expectException(MassAssignmentException::class);

    User::create([
        'name' => 'Test User',
        'email' => 'test2@example.com',
        'password' => 'password',
        'longest_streak' => 100,
    ]);
});

test('last_workout_at cannot be mass assigned', function () {
    $this->expectException(MassAssignmentException::class);

    User::create([
        'name' => 'Test User',
        'email' => 'test3@example.com',
        'password' => 'password',
        'last_workout_at' => now(),
    ]);
});

test('stats cannot be mass assigned during update', function () {
    $user = User::factory()->create();

    $this->expectException(MassAssignmentException::class);

    $user->update([
        'current_streak' => 100,
    ]);
});
