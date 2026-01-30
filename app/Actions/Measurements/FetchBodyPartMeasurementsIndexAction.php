<?php

declare(strict_types=1);

namespace App\Actions\Measurements;

use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FetchBodyPartMeasurementsIndexAction
{
    /**
     * @return Collection<int, array{part: string, current: float, unit: string, date: string, diff: float}>
     */
    public function execute(User $user): Collection
    {
        // Optimized query: fetch only latest 2 measurements per part
        // Uses Window Function to avoid N+1 and loading full history
        $table = (new BodyPartMeasurement())->getTable();
        $measurements = BodyPartMeasurement::fromQuery("
            SELECT * FROM (
                SELECT *, ROW_NUMBER() OVER (PARTITION BY part ORDER BY measured_at DESC) as rn
                FROM {$table}
                WHERE user_id = ?
            ) as ranked
            WHERE rn <= 2
        ", [$user->id]);

        // Group by part, get latest for card display
        return $measurements
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
                    'diff' => $previous ? (float) round($latest->value - $previous->value, 2) : 0.0,
                ];
            })->values();
    }
}
