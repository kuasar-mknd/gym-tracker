<?php

declare(strict_types=1);

namespace App\Actions\Habits;

use App\Models\Habit;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\QueryBuilder;

class FetchApiHabitsIndexAction
{
    /**
     * Fetch paginated list of habits for the given user, supporting includes and sorts via QueryBuilder.
     *
     * @param  User  $user  The authenticated user.
     * @param  array<string, mixed>  $validated  The validated request data containing pagination options.
     * @return LengthAwarePaginator<int, Habit> The paginated list of habits.
     */
    public function execute(User $user, array $validated): LengthAwarePaginator
    {
        /** @var \Spatie\QueryBuilder\QueryBuilder<\App\Models\Habit> $query */
        $query = clone QueryBuilder::for(Habit::class)
            ->allowedIncludes(['logs'])
            ->allowedSorts(['name', 'created_at', 'goal_times_per_week'])
            ->defaultSort('name')
            ->where('user_id', $user->id);

        /** @var int $perPage */
        $perPage = $validated['per_page'] ?? 15;

        return $query->paginate($perPage);
    }
}
