<?php

declare(strict_types=1);

use App\Actions\Journal\SaveDailyJournalAction;
use App\Models\DailyJournal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('creates a new daily journal entry', function (): void {
    $user = User::factory()->create();
    $action = app(SaveDailyJournalAction::class);

    $date = Carbon::now()->format('Y-m-d');
    $data = [
        'date' => $date,
        'content' => 'This is a test journal entry.',
    ];

    $journal = $action->execute($user, $data);

    expect($journal->user_id)->toBe($user->id)
        ->and($journal->date->format('Y-m-d'))->toBe($date)
        ->and($journal->content)->toBe('This is a test journal entry.');
});

it('updates an existing daily journal entry', function (): void {
    $user = User::factory()->create();
    $action = app(SaveDailyJournalAction::class);

    $date = Carbon::now()->format('Y-m-d');

    // First save
    $data1 = [
        'date' => $date,
        'content' => 'Initial content',
    ];
    $action->execute($user, $data1);

    // Update
    $data2 = [
        'date' => $date,
        'content' => 'Updated content',
    ];
    $journal = $action->execute($user, $data2);

    expect($journal->user_id)->toBe($user->id)
        ->and($journal->date->format('Y-m-d'))->toBe($date)
        ->and($journal->content)->toBe('Updated content');

    expect($user->dailyJournals()->count())->toBe(1);
});

it('met à jour l\'entrée qu\'un enregistrement croisé vient de créer entre la lecture et l\'écriture', function (): void {
    $user = User::factory()->create();
    $date = '2026-11-16';
    $croise = false;

    DailyJournal::creating(function () use ($user, $date, &$croise): void {
        if ($croise) {
            return;
        }

        $croise = true;
        DB::table('daily_journals')->insert([
            'user_id' => $user->id,
            'date' => $date,
            'content' => 'Écrit par l\'autre requête',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $journal = app(SaveDailyJournalAction::class)->execute($user, ['date' => $date, 'content' => 'Écrit par celle-ci']);

    expect($croise)->toBeTrue()
        ->and($user->dailyJournals()->count())->toBe(1)
        ->and($journal->content)->toBe('Écrit par celle-ci')
        ->and($user->dailyJournals()->first()?->content)->toBe('Écrit par celle-ci');
});

it('throws exception if date is missing', function (): void {
    $user = User::factory()->create();
    $action = app(SaveDailyJournalAction::class);

    $data = [
        'content' => 'Missing date',
    ];

    expect(fn () => $action->execute($user, $data))
        ->toThrow(UnexpectedValueException::class, 'Date must be a string');
});

it('throws exception if date is not a string', function (): void {
    $user = User::factory()->create();
    $action = app(SaveDailyJournalAction::class);

    $data = [
        'date' => 12345,
        'content' => 'Integer date',
    ];

    expect(fn () => $action->execute($user, $data))
        ->toThrow(UnexpectedValueException::class, 'Date must be a string');
});

it('throws exception if date is null', function (): void {
    $user = User::factory()->create();
    $action = app(SaveDailyJournalAction::class);

    $data = [
        'date' => null,
        'content' => 'Null date',
    ];

    expect(fn () => $action->execute($user, $data))
        ->toThrow(UnexpectedValueException::class, 'Date must be a string');
});
