<?php

declare(strict_types=1);

namespace App\Actions\Injuries;

use App\Models\Injury;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class FetchInjuriesIndexAction
{
    /**
     * @return Collection<int, Injury>
     */
    public function execute(User $user): Collection
    {
        return Injury::query()
            ->where('user_id', $user->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 1 WHEN status = 'recovering' THEN 2 ELSE 3 END")
            ->orderByDesc('injured_at')
            ->get();
    }
}
