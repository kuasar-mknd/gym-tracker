<?php

declare(strict_types=1);

namespace App\Actions\Journal;

use App\Models\DailyJournal;
use App\Models\User;
use Illuminate\Support\Carbon;
use UnexpectedValueException;

class CreateOrUpdateDailyJournalAction
{
    /**
     * @param  array<string, mixed>  $data
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
