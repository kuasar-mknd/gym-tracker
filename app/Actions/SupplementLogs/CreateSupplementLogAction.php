<?php

declare(strict_types=1);

namespace App\Actions\SupplementLogs;

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateSupplementLogAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): SupplementLog
    {
        return DB::transaction(function () use ($user, $data) {
            $log = new SupplementLog($data);
            /** @var int<0, max> $userId */
            $userId = (int) $user->id;
            $log->user_id = $userId;
            $log->save();

            /** @var int $supplementId */
            $supplementId = $data['supplement_id'];

            /** @var \App\Models\Supplement|null $supplement */
            $supplement = Supplement::find($supplementId);

            if ($supplement instanceof Supplement && $supplement->servings_remaining > 0) {
                 /** @var int $quantity */
                 $quantity = $data['quantity'];
                 $supplement->decrement('servings_remaining', $quantity);
            }

            return $log;
        });
    }
}
