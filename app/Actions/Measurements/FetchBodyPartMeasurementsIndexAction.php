<?php

declare(strict_types=1);

namespace App\Actions\Measurements;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class FetchBodyPartMeasurementsIndexAction
{
    /**
     * @return \Illuminate\Support\Collection<int, array{part: string, current: string, unit: string, date: string, diff: float}>
     */
    public function execute(User $user): Collection
    {
        return $user->bodyPartMeasurements()
            ->orderBy('measured_at', 'desc')
            ->get()
            ->groupBy('part')
            ->map(function ($group): array {
                /** @var \App\Models\BodyPartMeasurement $latest */
                $latest = $group->first();
                /** @var \App\Models\BodyPartMeasurement|null $previous */
                $previous = $group->skip(1)->first();

                return [
                    'part' => $latest->part,
                    'current' => $latest->value,
                    'unit' => $latest->unit,
                    'date' => Carbon::parse($latest->measured_at)->format('Y-m-d'),
                    'diff' => $previous ? round((float) $latest->value - (float) $previous->value, 2) : 0,
                ];
            })->values();
    }
}
