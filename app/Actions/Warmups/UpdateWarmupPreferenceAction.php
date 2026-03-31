<?php

declare(strict_types=1);

namespace App\Actions\Warmups;

use App\Models\User;
use App\Models\WarmupPreference;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class UpdateWarmupPreferenceAction
{
    use AuthorizesRequests;

    /**
     * Execute the action to update or create warmup preferences.
     *
     * @param  \App\Models\User  $user  The user making the request.
     * @param  array  $validated  The validated data to update or create.
     */
    public function execute(User $user, array $validated): WarmupPreference
    {
        $preference = $user->warmupPreference;

        if ($preference) {
            $this->authorize('update', $preference);
        } else {
            $this->authorize('create', WarmupPreference::class);
        }

        /** @var WarmupPreference $warmupPreference */
        $warmupPreference = $user->warmupPreference()->updateOrCreate(
            ['user_id' => $user->id],
            $validated
        );

        return $warmupPreference;
    }
}
