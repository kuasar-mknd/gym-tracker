<?php

declare(strict_types=1);

namespace App\Actions\Supplements;

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;

final class ConsumeSupplementAction
{
    public function execute(User $user, Supplement $supplement): void
    {
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
