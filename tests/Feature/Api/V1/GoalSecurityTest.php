<?php

use App\Models\Exercise;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Goal Security', function () {
    test('user cannot create goal with another users private exercise', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $otherUser = User::factory()->create();
        $privateExercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

        $data = [
            'title' => 'Hacked Goal',
            'type' => 'weight',
            'target_value' => 100,
            'exercise_id' => $privateExercise->id,
        ];

        postJson(route('api.v1.goals.store'), $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exercise_id']);
    });

    test('user cannot create goal with another users private exercise even if they own an exercise', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // User owns an exercise (this would trigger the regression if OR is not grouped)
        Exercise::factory()->create(['user_id' => $user->id]);

        $otherUser = User::factory()->create();
        $privateExercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

        $data = [
            'title' => 'Hacked Goal 2',
            'type' => 'weight',
            'target_value' => 100,
            'exercise_id' => $privateExercise->id,
        ];

        postJson(route('api.v1.goals.store'), $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exercise_id']);
    });
});
