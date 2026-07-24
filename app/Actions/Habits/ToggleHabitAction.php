<?php

declare(strict_types=1);

namespace App\Actions\Habits;

use App\Models\Habit;
use App\Models\HabitLog;

class ToggleHabitAction
{
    /**
     * Toggle the completion status of a habit for a specific date.
     *
     * @param  Habit  $habit  The habit to toggle.
     * @param  string  $date  The date to toggle.
     */
    public function execute(Habit $habit, string $date): void
    {
        /** @var HabitLog|null $log */
        $log = $habit->logs()->whereDate('date', $date)->first();

        if ($log) {
            $log->delete();
        } else {
            $habit->logs()->create(['date' => $date]);
        }
    }
}
