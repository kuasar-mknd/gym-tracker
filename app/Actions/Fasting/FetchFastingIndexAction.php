<?php

declare(strict_types=1);

namespace App\Actions\Fasting;

use App\Models\User;

class FetchFastingIndexAction
{
    /**
     * Fetch the fasting index data for the given user.
     *
     * @return array{activeFast: \App\Models\Fast|null, history: \Illuminate\Contracts\Pagination\LengthAwarePaginator}
     */
    public function execute(User $user): array
    {
        /** @var \App\Models\Fast|null $activeFast */
        $activeFast = $user->fasts()
            ->where('status', 'active')
            ->latest()
            ->first();

        $history = $user->fasts()
            ->where('status', '!=', 'active')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return [
            'activeFast' => $activeFast,
            'history' => $history,
        ];
    }
}
