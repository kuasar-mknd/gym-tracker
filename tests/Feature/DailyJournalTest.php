<?php

declare(strict_types=1);

use App\Models\DailyJournal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

test('authenticated user can view daily journal index', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('daily-journals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Journal/Index')
            ->has('journals')
        );
});

test('authenticated user can create a daily journal entry', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('daily-journals.store'), [
            'date' => now()->format('Y-m-d'),
            'content' => 'Today was a great day!',
            'mood_score' => 5,
            'sleep_quality' => 4,
            'stress_level' => 2,
            'energy_level' => 8,
            'motivation_level' => 9,
            'nutrition_score' => 5,
            'training_intensity' => 7,
        ])
        ->assertRedirect();

    assertDatabaseHas('daily_journals', [
        'user_id' => $user->id,
        'date' => now()->format('Y-m-d'),
        'content' => 'Today was a great day!',
        'mood_score' => 5,
    ]);
});

test('authenticated user can update an existing journal entry for the same date', function (): void {
    $user = User::factory()->create();
    $date = now()->format('Y-m-d');

    // Create initial entry
    DailyJournal::factory()->create([
        'user_id' => $user->id,
        'date' => $date,
        'content' => 'Initial content',
        'mood_score' => 3,
    ]);

    // Send post request with same date but new data
    actingAs($user)
        ->post(route('daily-journals.store'), [
            'date' => $date,
            'content' => 'Updated content',
            'mood_score' => 5,
        ])
        ->assertRedirect();

    // Should update existing record, not create new one
    expect(DailyJournal::where('user_id', $user->id)->count())->toBe(1);

    assertDatabaseHas('daily_journals', [
        'user_id' => $user->id,
        'date' => $date,
        'content' => 'Updated content',
        'mood_score' => 5,
    ]);
});

test('authenticated user can delete their own journal entry', function (): void {
    $user = User::factory()->create();
    $journal = DailyJournal::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->delete(route('daily-journals.destroy', $journal))
        ->assertRedirect();

    assertDatabaseMissing('daily_journals', ['id' => $journal->id]);
});

// Validation Tests
test('validation: date is required', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('daily-journals.store'), [
            'content' => 'Content without date',
        ])
        ->assertSessionHasErrors('date');
});

test('validation: content max length', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('daily-journals.store'), [
            'date' => now()->format('Y-m-d'),
            'content' => str_repeat('a', 5001), // Max is 5000
        ])
        ->assertSessionHasErrors('content');
});

test('validation: mood score range 1-5', function (): void {
    $user = User::factory()->create();
    $date = now()->format('Y-m-d');

    // Too low
    actingAs($user)
        ->post(route('daily-journals.store'), ['date' => $date, 'mood_score' => 0])
        ->assertSessionHasErrors('mood_score');

    // Too high
    actingAs($user)
        ->post(route('daily-journals.store'), ['date' => $date, 'mood_score' => 6])
        ->assertSessionHasErrors('mood_score');
});

test('validation: sleep quality range 1-5', function (): void {
    $user = User::factory()->create();
    $date = now()->format('Y-m-d');

    actingAs($user)
        ->post(route('daily-journals.store'), ['date' => $date, 'sleep_quality' => 6])
        ->assertSessionHasErrors('sleep_quality');
});

test('validation: stress level range 1-10', function (): void {
    $user = User::factory()->create();
    $date = now()->format('Y-m-d');

    actingAs($user)
        ->post(route('daily-journals.store'), ['date' => $date, 'stress_level' => 11])
        ->assertSessionHasErrors('stress_level');
});

test('validation: energy level range 1-10', function (): void {
    $user = User::factory()->create();
    $date = now()->format('Y-m-d');

    actingAs($user)
        ->post(route('daily-journals.store'), ['date' => $date, 'energy_level' => 11])
        ->assertSessionHasErrors('energy_level');
});

test('validation: motivation level range 1-10', function (): void {
    $user = User::factory()->create();
    $date = now()->format('Y-m-d');

    actingAs($user)
        ->post(route('daily-journals.store'), ['date' => $date, 'motivation_level' => 11])
        ->assertSessionHasErrors('motivation_level');
});

test('validation: nutrition score range 1-5', function (): void {
    $user = User::factory()->create();
    $date = now()->format('Y-m-d');

    actingAs($user)
        ->post(route('daily-journals.store'), ['date' => $date, 'nutrition_score' => 6])
        ->assertSessionHasErrors('nutrition_score');
});

test('validation: training intensity range 1-10', function (): void {
    $user = User::factory()->create();
    $date = now()->format('Y-m-d');

    actingAs($user)
        ->post(route('daily-journals.store'), ['date' => $date, 'training_intensity' => 11])
        ->assertSessionHasErrors('training_intensity');
});

// Authorization Tests
test('unauthenticated user cannot access journal pages', function (): void {
    get(route('daily-journals.index'))->assertRedirect(route('login'));

    post(route('daily-journals.store'), [
        'date' => now()->format('Y-m-d'),
        'content' => 'Test',
    ])->assertRedirect(route('login'));
});

test('user cannot delete another users journal entry', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherJournal = DailyJournal::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->delete(route('daily-journals.destroy', $otherJournal))
        ->assertForbidden();

    assertDatabaseHas('daily_journals', ['id' => $otherJournal->id]);
});
