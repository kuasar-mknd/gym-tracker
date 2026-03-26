<?php

declare(strict_types=1);

namespace App\Actions\Measurements;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class FetchBodyPartMeasurementShowAction
{
    /**
     * Fetch the body part measurement history for a specific part.
     *
     * @return array{part: string, history: Collection<int, \App\Models\BodyPartMeasurement>}|null
     */
    public function execute(User $user, string $part): ?array
    {
        $history = $user->bodyPartMeasurements()
            ->where('part', $part)
            ->orderBy('measured_at', 'asc')
            ->get();

        if ($history->isEmpty()) {
            return null;
        }

        return [
            'part' => $part,
            'history' => $history,
        ];
    }
}
