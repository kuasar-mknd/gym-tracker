<?php

declare(strict_types=1);

namespace App\Actions\Supplements;

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ConsumeSupplementAction
{
    /**
     * La portion se retire par une écriture conditionnelle : deux prises
     * simultanées à stock 1 n'en retirent qu'une, jamais sous zéro. Un stock
     * déjà vide journalise la prise sans rien retirer.
     */
    public function execute(User $user, Supplement $supplement): void
    {
        DB::transaction(function () use ($user, $supplement): void {
            SupplementLog::create([
                'user_id' => $user->id,
                'supplement_id' => $supplement->id,
                'quantity' => 1,
                'consumed_at' => now(),
            ]);

            Supplement::query()
                ->whereKey($supplement->id)
                ->where('servings_remaining', '>', 0)
                ->decrement('servings_remaining');
        });
    }
}
