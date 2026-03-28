<?php

declare(strict_types=1);

namespace App\Actions\Measurements;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class FetchBodyPartMeasurementShowAction
{
    /**
     * Fetch the measurement history for a specific body part.
     *
     * @param  \App\Models\User  $user
     * @param  string  $part
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\BodyPartMeasurement>
     */
    public function execute(User $user, string $part): Collection
    {
        return $user->bodyPartMeasurements()
            ->where('part', $part)
            ->orderBy('measured_at', 'asc')
            ->get();
    }
}
