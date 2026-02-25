<?php

declare(strict_types=1);

namespace App\Actions\Journal;

use App\Models\DailyJournal;
use App\Models\User;
use Illuminate\Support\Carbon;
use UnexpectedValueException;

final class LogDailyJournalAction
{
    /**
     * Log a daily journal entry for the user.
     *
     * @param  User  $user  The user to log the journal for.
     * @param  array  $data  The validated data for the journal entry.
     * @return DailyJournal The created or updated journal entry.
     */
    public function execute(User $user, array $data): DailyJournal
    {
        $dateInput = $data['date'];

        if (! is_string($dateInput)) {
            throw new UnexpectedValueException('Date must be a string');
        }

        $date = Carbon::parse($dateInput);
        $dateString = $date->format('Y-m-d');

        /** @var DailyJournal $journal */
        $journal = $user->dailyJournals()->where('date', $dateString)->first() ?? new DailyJournal();

        if (! $journal->exists) {
            $journal->user_id = $user->id;
            $journal->date = $date;
        }

        $journal->fill($data);
        $journal->save();

        return $journal;
    }
}
