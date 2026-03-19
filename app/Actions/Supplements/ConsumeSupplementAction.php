<?php

declare(strict_types=1);

namespace App\Actions\Supplements;

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;

class ConsumeSupplementAction
{
    /**
     * Record a consumption event for a supplement.
     *
     * Creates a log entry for the consumption and decrements the inventory count.
     * Prevents the inventory from going below zero.
     *
     * @param  User  $user  The user consuming the supplement.
     * @param  Supplement  $supplement  The supplement consumed.
     */
    public function execute(User $user, Supplement $supplement): void
    {
        // Create log
        SupplementLog::create([
            'user_id' => $user->id,
            'supplement_id' => $supplement->id,
            'quantity' => 1,
            'consumed_at' => now(),
        ]);

        if ($supplement->servings_remaining > 0) {
            $supplement->decrement('servings_remaining');
        }
    }
}
