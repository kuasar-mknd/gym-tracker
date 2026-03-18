<?php

declare(strict_types=1);

namespace App\Actions\Habits;

use App\Models\Habit;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\QueryBuilder;

class FetchHabitsIndexApiAction
{
    /**
     * Fetch paginated list of habits for the given user via API.
     *
     * @param  User  $user  The authenticated user.
     * @param  array<string, mixed>  $requestData  The validated request data.
     * @return LengthAwarePaginator<int, Habit> The paginated habits list.
     */
    public function execute(User $user, array $requestData): LengthAwarePaginator
    {
        $query = QueryBuilder::for(Habit::class)
            ->allowedIncludes(['logs'])
            ->allowedSorts(['name', 'created_at', 'goal_times_per_week'])
            ->defaultSort('name')
            ->where('user_id', $user->id);

        /** @var int $perPage */
        $perPage = $requestData['per_page'] ?? 15;

        return $query->paginate($perPage);
    }
}
