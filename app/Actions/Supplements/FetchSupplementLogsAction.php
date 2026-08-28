<?php

declare(strict_types=1);

namespace App\Actions\Supplements;

use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class FetchSupplementLogsAction
{
    /**
     * Fetch paginated supplement logs for a specific user.
     *
     * @param  User  $user  The user to fetch logs for.
     * @param  int  $perPage  Number of items per page.
     * @return LengthAwarePaginator<int, SupplementLog>
     */
    public function execute(User $user, int $perPage): LengthAwarePaginator
    {
        return QueryBuilder::for(SupplementLog::class)
            // Passe en CHAINE, Spatie en fait un filtre partiel : un
            // `LOWER(supplement_id) LIKE '%1%'` qui remonte aussi 10, 21, 31…
            // La forme exacte est deja employee dans le journal frere.
            ->allowedFilters(AllowedFilter::exact('supplement_id'))
            ->allowedSorts('consumed_at', 'created_at')
            ->allowedIncludes('supplement')
            ->where('user_id', $user->id)
            ->paginate($perPage);
    }
}
