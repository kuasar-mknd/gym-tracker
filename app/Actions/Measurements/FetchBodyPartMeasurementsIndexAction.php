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
     * @return array{latestMeasurements: Collection<int, array{part: string, current: float, unit: string, date: string, diff: float}>, commonParts: array<int, string>}
     */
    public function execute(User $user): array
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
        $latestMeasurements = $measurements
            ->groupBy('part')
            ->map(function ($group): array {
                /** @var BodyPartMeasurement $latest */
                $latest = $group->first();
                /** @var BodyPartMeasurement|null $previous */
                $previous = $group->skip(1)->first();

                return [
                    'part' => $latest->part,
                    'current' => (float) $latest->value,
                    'unit' => $latest->unit,
                    'date' => Carbon::parse($latest->measured_at)->format('Y-m-d'),
                    'diff' => $previous ? round((float) $latest->value - (float) $previous->value, 2) : 0.0,
                ];
            })->values();

        return [
            'latestMeasurements' => $latestMeasurements,
            'commonParts' => $this->getCommonParts(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function getCommonParts(): array
    {
        return [
            'Neck',
            'Shoulders',
            'Chest',
            'Biceps L',
            'Biceps R',
            'Forearm L',
            'Forearm R',
            'Waist',
            'Hips',
            'Thigh L',
            'Thigh R',
            'Calf L',
            'Calf R',
        ];
    }
}
