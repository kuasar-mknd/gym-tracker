<?php

use App\Models\DailyJournal;
use App\Models\User;

test('index returns list of daily journals', function () {
    $user = User::factory()->create();
    DailyJournal::factory()->count(3)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/daily-journals');

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('store creates a new daily journal', function () {
    $user = User::factory()->create();
    $data = [
        'date' => '2023-10-27',
        'content' => 'Great day!',
        'mood_score' => 5,
        'sleep_quality' => 4,
    ];

    $response = $this->actingAs($user)
        ->postJson('/api/v1/daily-journals', $data);

    $response->assertCreated()
        ->assertJsonFragment(['content' => 'Great day!']);

    $this->assertDatabaseHas('daily_journals', [
        'user_id' => $user->id,
        'date' => '2023-10-27',
        'content' => 'Great day!',
    ]);
});

test('store validation fails for existing date', function () {
    $user = User::factory()->create();
    DailyJournal::factory()->create(['user_id' => $user->id, 'date' => '2023-10-27']);

    $data = [
        'date' => '2023-10-27',
        'content' => 'Another entry',
    ];

    $response = $this->actingAs($user)
        ->postJson('/api/v1/daily-journals', $data);

    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'A journal entry for this date already exists.']);
});

test('show returns a daily journal', function () {
    $user = User::factory()->create();
    $journal = DailyJournal::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/daily-journals/{$journal->id}");

    $response->assertOk()
        ->assertJsonFragment(['id' => $journal->id]);
});

test('update modifies a daily journal', function () {
    $user = User::factory()->create();
    $journal = DailyJournal::factory()->create(['user_id' => $user->id, 'content' => 'Old content']);

    $data = [
        'content' => 'New content',
    ];

    $response = $this->actingAs($user)
        ->putJson("/api/v1/daily-journals/{$journal->id}", $data);

    $response->assertOk()
        ->assertJsonFragment(['content' => 'New content']);

    $this->assertDatabaseHas('daily_journals', [
        'id' => $journal->id,
        'content' => 'New content',
    ]);
});

test('update validation fails for existing date conflict', function () {
    $user = User::factory()->create();
    DailyJournal::factory()->create(['user_id' => $user->id, 'date' => '2023-10-27']);
    $journal = DailyJournal::factory()->create(['user_id' => $user->id, 'date' => '2023-10-28']);

    $data = [
        'date' => '2023-10-27', // Conflict
    ];

    $response = $this->actingAs($user)
        ->putJson("/api/v1/daily-journals/{$journal->id}", $data);

    $response->assertStatus(422);
});

test('destroy deletes a daily journal', function () {
    $user = User::factory()->create();
    $journal = DailyJournal::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/daily-journals/{$journal->id}");

    $response->assertNoContent();

    $this->assertDatabaseMissing('daily_journals', ['id' => $journal->id]);
});

test('cannot access others daily journals', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $journal = DailyJournal::factory()->create(['user_id' => $user1->id]);

    $response = $this->actingAs($user2)
        ->getJson("/api/v1/daily-journals/{$journal->id}");

    $response->assertForbidden();
});
