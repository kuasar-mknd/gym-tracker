<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use Spatie\QueryBuilder\QueryBuilder;

class FetchExercisesApiAction
{
    /**
     * Fetch a paginated list of exercises for the API.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Exercise>
     */
    public function execute(User $user): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return QueryBuilder::for(Exercise::class)
            ->allowedFilters(['name', 'type', 'category'])
            ->allowedSorts(['name', 'created_at'])
            ->defaultSort('name')
            ->where(function ($query) use ($user): void {
                $query->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            })
            ->paginate();
    }
}
