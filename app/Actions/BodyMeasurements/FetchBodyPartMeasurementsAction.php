<?php

declare(strict_types=1);

namespace App\Actions\BodyMeasurements;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FetchBodyPartMeasurementsAction
{
    /**
     * @return Collection<int, array{part: string, current: float, unit: string, date: string, diff: float|int}>
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
                    'diff' => $previous ? round($latest->value - $previous->value, 2) : 0,
                ];
            })->values();
    }
}
