<?php

declare(strict_types=1);

use App\Actions\Journal\SaveDailyJournalAction;
use App\Models\DailyJournal;
use App\Models\User;
use Illuminate\Support\Carbon;

it('creates a new daily journal entry if none exists for the given date', function (): void {
    $user = User::factory()->create();
    $action = app(SaveDailyJournalAction::class);

    $date = '2023-10-10';
    $data = [
        'date' => $date,
        'content' => 'Test journal entry',
        'mood_score' => 8,
    ];

    $journal = $action->execute($user, $data);

    expect($journal)->toBeInstanceOf(DailyJournal::class)
        ->and($journal->user_id)->toBe($user->id)
        ->and($journal->date->format('Y-m-d'))->toBe($date)
        ->and($journal->content)->toBe('Test journal entry')
        ->and($journal->mood_score)->toBe(8);

    $this->assertDatabaseHas('daily_journals', [
        'user_id' => $user->id,
        'date' => $date,
        'content' => 'Test journal entry',
        'mood_score' => 8,
    ]);
});

it('updates an existing daily journal entry if one exists for the given date', function (): void {
    $user = User::factory()->create();
    $date = '2023-10-10';

    $existingJournal = DailyJournal::factory()->create([
        'user_id' => $user->id,
        'date' => $date,
        'content' => 'Old content',
        'mood_score' => 5,
    ]);

    $action = app(SaveDailyJournalAction::class);

    $data = [
        'date' => $date,
        'content' => 'Updated content',
        'mood_score' => 9,
    ];

    $journal = $action->execute($user, $data);

    expect($journal->id)->toBe($existingJournal->id)
        ->and($journal->content)->toBe('Updated content')
        ->and($journal->mood_score)->toBe(9);

    $this->assertDatabaseHas('daily_journals', [
        'id' => $existingJournal->id,
        'user_id' => $user->id,
        'date' => $date,
        'content' => 'Updated content',
        'mood_score' => 9,
    ]);

    $this->assertDatabaseMissing('daily_journals', [
        'id' => $existingJournal->id,
        'content' => 'Old content',
    ]);
});

it('throws exception if date is missing', function (): void {
    $user = User::factory()->create();
    $action = app(SaveDailyJournalAction::class);

    $data = [
        'content' => 'Test journal entry',
    ];

    expect(fn () => $action->execute($user, $data))->toThrow(UnexpectedValueException::class, 'Date must be a string');
});

it('throws exception if date is not a string', function (): void {
    $user = User::factory()->create();
    $action = app(SaveDailyJournalAction::class);

    $data = [
        'date' => 20231010,
        'content' => 'Test journal entry',
    ];

    expect(fn () => $action->execute($user, $data))->toThrow(UnexpectedValueException::class, 'Date must be a string');
});
