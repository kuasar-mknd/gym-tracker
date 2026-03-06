<?php

declare(strict_types=1);

namespace App\Actions\Habits;

use App\Models\Habit;
use App\Models\User;

class CreateHabitAction
{
    /**
     * Create a new Habit for the given User.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): Habit
    {
        if (($data['color'] ?? null) === null) {
            $data['color'] = 'bg-slate-500';
        }
        if (($data['icon'] ?? null) === null) {
            $data['icon'] = 'check_circle';
        }

        $habit = new Habit($data);
        $habit->user_id = $user->id;
        $habit->save();

        return $habit;
    }
}
