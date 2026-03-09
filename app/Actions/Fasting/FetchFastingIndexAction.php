<?php

declare(strict_types=1);

namespace App\Actions\Fasting;

use App\Models\User;

class FetchFastingIndexAction
{
    /**
     * Execute the action.
     *
     * @return array<string, mixed>
     */
    public function execute(User $user): array
    {
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
