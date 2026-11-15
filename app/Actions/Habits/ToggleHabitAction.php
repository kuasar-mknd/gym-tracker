<?php

declare(strict_types=1);

namespace App\Actions\Habits;

use App\Models\Habit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

class ToggleHabitAction
{
    public function execute(Habit $habit, string $date): void
    {
        /*
         * La date est normalisee avant la comparaison, pas pendant.
         *
         * `whereDate()` appliquait une fonction a la COLONNE, ce qui interdit
         * l'index — ici la clef UNIQUE `(habit_id, date)`. Comparer la colonne
         * nue la rend utilisable, mais impose que la valeur ait la bonne forme :
         * la regle de validation est `['required', 'date']`, qui accepte aussi
         * bien `2026-08-27` qu'un horodatage complet.
         */
        $jour = Carbon::parse($date)->toDateString();

        /** @var \App\Models\HabitLog|null $log */
        $log = $habit->logs()->where('date', $jour)->first();

        if ($log instanceof \App\Models\HabitLog) {
            $log->delete();
        } else {
            try {
                $habit->logs()->create(['date' => $jour]);
            } catch (UniqueConstraintViolationException) {
                // Un second appui, croisé avec celui-ci, a déjà coché la case : elle l'est.
                return;
            }
        }
    }
}
