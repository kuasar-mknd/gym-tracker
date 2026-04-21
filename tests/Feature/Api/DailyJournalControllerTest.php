<?php

declare(strict_types=1);

use App\Models\DailyJournal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a list of daily journals for the authenticated user', function () {
    $user = User::factory()->create();
    DailyJournal::factory()->count(3)->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->getJson(route('api.v1.daily-journals.index'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [
            '*' => ['id', 'date', 'content', 'mood_score', 'sleep_quality'],
        ],
    ]);
    $response->assertJsonCount(3, 'data');
});

it('creates a new daily journal', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('api.v1.daily-journals.store'), [
        'date' => now()->format('Y-m-d'),
        'content' => 'Test journal content',
        'mood_score' => 4,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.content', 'Test journal content');
    $this->assertDatabaseHas('daily_journals', [
        'user_id' => $user->id,
        'content' => 'Test journal content',
    ]);
});

it('returns 422 Unprocessable when creating a journal with invalid data', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('api.v1.daily-journals.store'), [
        'date' => now()->format('Y-m-d'),
        'mood_score' => 10, // Invalid: max 5
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['mood_score']);
});

it('returns 403 Forbidden when trying to view another user\'s journal', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $journal = DailyJournal::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)->getJson(route('api.v1.daily-journals.show', $journal));

    $response->assertStatus(403);
});
