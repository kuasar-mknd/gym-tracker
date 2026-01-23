<?php

declare(strict_types=1);

namespace App\Actions\SupplementLogs;

use App\Models\Supplement;
use App\Models\SupplementLog;
use Illuminate\Support\Facades\DB;

class DeleteSupplementLogAction
{
    public function execute(SupplementLog $supplementLog): void
    {
        DB::transaction(function () use ($supplementLog) {
            $quantity = $supplementLog->quantity;
            $supplementId = $supplementLog->supplement_id;

            $supplementLog->delete();

            $supplement = Supplement::find($supplementId);
            if ($supplement) {
                $supplement->increment('servings_remaining', $quantity);
            }
        });
    }
}
