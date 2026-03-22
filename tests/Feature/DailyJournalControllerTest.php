<?php

declare(strict_types=1);

use App\Models\DailyJournal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the daily journal index page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/daily-journals')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Journal/Index')
                ->has('journals')
        );
});

it('prevents unauthenticated users from viewing journals', function (): void {
    $this->get('/daily-journals')
        ->assertRedirect('/login');
});

it('creates a new daily journal entry', function (): void {
    $user = User::factory()->create();
    $date = Carbon::today()->format('Y-m-d');

    $this->actingAs($user)
        ->post('/daily-journals', [
            'date' => $date,
            'content' => 'Test journal entry',
            'mood_score' => 4,
            'sleep_quality' => 3,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('daily_journals', [
        'user_id' => $user->id,
        'date' => $date,
        'content' => 'Test journal entry',
        'mood_score' => 4,
        'sleep_quality' => 3,
    ]);
});

it('updates an existing daily journal entry on the same date', function (): void {
    $user = User::factory()->create();
    $date = Carbon::today()->format('Y-m-d');

    DailyJournal::factory()->create([
        'user_id' => $user->id,
        'date' => $date,
        'content' => 'Original entry',
    ]);

    $this->actingAs($user)
        ->post('/daily-journals', [
            'date' => $date,
            'content' => 'Updated entry',
            'mood_score' => 5,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('daily_journals', [
        'user_id' => $user->id,
        'date' => $date,
        'content' => 'Updated entry',
        'mood_score' => 5,
    ]);
});

it('fails to create a journal entry with missing date', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/daily-journals', [
            'content' => 'Test journal entry',
        ])
        ->assertStatus(302)
        ->assertSessionHasErrors(['date']);
});

it('fails to create a journal entry with invalid mood score', function (): void {
    $user = User::factory()->create();
    $date = Carbon::today()->format('Y-m-d');

    $this->actingAs($user)
        ->post('/daily-journals', [
            'date' => $date,
            'mood_score' => 10, // Max is 5
        ])
        ->assertStatus(302)
        ->assertSessionHasErrors(['mood_score']);
});

it('deletes a daily journal entry', function (): void {
    $user = User::factory()->create();
    $journal = DailyJournal::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete('/daily-journals/'.$journal->id)
        ->assertRedirect();

    $this->assertDatabaseMissing('daily_journals', ['id' => $journal->id]);
});

it('prevents a user from deleting another user\'s journal entry', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $journal = DailyJournal::factory()->create(['user_id' => $user1->id]);

    $this->actingAs($user2)
        ->delete('/daily-journals/'.$journal->id)
        ->assertStatus(403);

    $this->assertDatabaseHas('daily_journals', ['id' => $journal->id]);
});
