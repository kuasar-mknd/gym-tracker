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
        /*
         * Par `start_time`, pas par `created_at` : les deux index de `fasts`
         * portent le premier, aucun le second. Et `Api\FastController` triait
         * deja ainsi — un jeune saisi apres coup ne se rangeait donc pas a la
         * meme place selon qu'on le regardait par le web ou par l'API.
         */
        $activeFast = $user->fasts()
            ->where('status', 'active')
            ->latest('start_time')
            ->first();

        $history = $user->fasts()
            ->where('status', '!=', 'active')
            ->latest('start_time')
            ->paginate(10)
            ->withQueryString();

        return [
            'activeFast' => $activeFast,
            'history' => $history,
        ];
    }
}
