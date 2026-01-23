<?php

declare(strict_types=1);

namespace App\Actions\SupplementLogs;

use App\Models\Supplement;
use App\Models\SupplementLog;
use Illuminate\Support\Facades\DB;

class UpdateSupplementLogAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(SupplementLog $supplementLog, array $data): SupplementLog
    {
        return DB::transaction(function () use ($supplementLog, $data) {
            $oldQuantity = $supplementLog->quantity;

            $supplementLog->update($data);

            if (isset($data['quantity'])) {
                /** @var int $newQuantity */
                $newQuantity = $data['quantity'];
                $diff = $newQuantity - $oldQuantity;

                if ($diff !== 0) {
                    /** @var \App\Models\Supplement|null $supplement */
                    $supplement = Supplement::find($supplementLog->supplement_id);
                    if ($supplement instanceof Supplement) {
                        $supplement->decrement('servings_remaining', $diff);
                    }
                }
            }

            return $supplementLog;
        });
    }
}
