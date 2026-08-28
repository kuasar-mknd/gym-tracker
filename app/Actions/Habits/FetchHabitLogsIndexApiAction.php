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
     * Le filtre passait par une jointure sur `habits`, l'ordre restait sur
     * `habit_logs` : aucun index ne pouvait servir les deux. Sur la colonne
     * denormalisee, `(user_id, date)` sert le filtre ET l'ordre, et la page
     * s'arrete a ses quinze lignes.
     *
     * @return LengthAwarePaginator<int, HabitLog>
     */
    public function execute(User $user): LengthAwarePaginator
    {
        return QueryBuilder::for(HabitLog::class)
            ->allowedIncludes('habit')
            ->allowedFilters(
                AllowedFilter::exact('habit_id'),
                AllowedFilter::scope('date_between', 'whereDateBetween'),
            )
            ->allowedSorts('date', 'created_at')
            ->defaultSort('-date')
            ->where('habit_logs.user_id', $user->id)
            ->paginate();
    }
}
