<?php

declare(strict_types=1);

namespace App\Actions\Habits;

use App\Models\HabitLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class FetchHabitLogsIndexApiAction
{
    /**
     * Fetch a paginated list of habit logs for the user.
     *
     * @return LengthAwarePaginator<int, HabitLog>
     */
    public function execute(User $user): LengthAwarePaginator
    {
        return QueryBuilder::for(HabitLog::class)
            ->allowedIncludes(['habit'])
            ->allowedFilters([
                AllowedFilter::exact('habit_id'),
                AllowedFilter::scope('date_between', 'whereDateBetween'),
            ])
            ->allowedSorts(['date', 'created_at'])
            ->defaultSort('-date')
            // Bolt: Optimize belongsTo filtering with INNER JOIN
            ->join('habits', 'habit_logs.habit_id', '=', 'habits.id')
            ->where('habits.user_id', $user->id)
            ->select('habit_logs.*')
            ->paginate();
    }
}
