<?php

declare(strict_types=1);

namespace App\Actions\Journal;

use App\Models\DailyJournal;
use App\Models\User;
use Illuminate\Support\Carbon;

class SaveDailyJournalAction
{
    /**
     * Écrit l'entrée du jour, ou la crée si elle n'existe pas encore.
     *
     * @param  array<string, mixed>  $data  Les données à écrire, dont la clef `date`.
     */
    public function execute(User $user, array $data): DailyJournal
    {
        $dateInput = $data['date'] ?? null;

        if (! is_string($dateInput)) {
            throw new \UnexpectedValueException('Date must be a string');
        }

        $date = Carbon::parse($dateInput);
        $dateString = $date->format('Y-m-d');

        /** @var DailyJournal|null $journal */
        $journal = $user->dailyJournals()->where('date', $dateString)->first();
        $journal ??= new DailyJournal();

        if (! $journal->exists) {
            $journal->user_id = $user->id;
            $journal->date = $date;
        }

        $journal->fill($data);
        $journal->save();

        return $journal;
    }
}
