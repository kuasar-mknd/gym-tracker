<?php

declare(strict_types=1);

namespace App\Actions\Habits;

use App\Models\Habit;
use App\Models\User;

final class CreateHabitAction
{
    /**
     * Create a new habit for the user.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): Habit
    {
        if (! ($data['color'] ?? null)) {
            $data['color'] = 'bg-slate-500';
        }
        if (! ($data['icon'] ?? null)) {
            $data['icon'] = 'check_circle';
        }

        /** @var Habit */
        return $user->habits()->create($data);
    }
}
