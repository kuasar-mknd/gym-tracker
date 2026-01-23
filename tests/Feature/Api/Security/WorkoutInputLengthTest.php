<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use Laravel\Sanctum\Sanctum;

test('store validation fails when notes exceed max length', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $longNotes = str_repeat('a', 1001);

    $response = $this->postJson(route('api.v1.workouts.store'), [
        'name' => 'Long Notes Workout',
        'started_at' => now()->toIso8601String(),
        'notes' => $longNotes,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['notes']);
});

test('update validation fails when notes exceed max length', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $longNotes = str_repeat('a', 1001);

    $response = $this->putJson(route('api.v1.workouts.update', $workout), [
        'notes' => $longNotes,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['notes']);
});
