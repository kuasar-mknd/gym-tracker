<?php

declare(strict_types=1);

namespace App\Actions\Supplements;

use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\QueryBuilder;

class FetchSupplementLogsAction
{
    /**
     * Fetch paginated supplement logs for a specific user.
     *
     * @param  User  $user  The user to fetch logs for.
     * @param  int  $perPage  Number of items per page.
     */
    public function execute(User $user, int $perPage): LengthAwarePaginator
    {
        return QueryBuilder::for(SupplementLog::class)
            ->allowedFilters(['supplement_id'])
            ->allowedSorts(['consumed_at', 'created_at'])
            ->allowedIncludes(['supplement'])
            ->where('user_id', $user->id)
            ->paginate($perPage);
    }
}
