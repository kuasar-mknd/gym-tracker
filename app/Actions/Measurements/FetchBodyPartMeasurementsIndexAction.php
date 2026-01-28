<?php

declare(strict_types=1);

namespace App\Actions\Measurements;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class FetchBodyPartMeasurementsIndexAction
{
    /**
     * @return array{
     *     latestMeasurements: Collection<int, array{
     *         part: string,
     *         current: string,
     *         unit: string,
     *         date: string,
     *         diff: float
     *     }>,
     *     commonParts: array<int, string>
     * }
     */
    public function execute(User $user): array
    {
        return [
            'latestMeasurements' => $this->getLatestMeasurements($user),
            'commonParts' => $this->getCommonParts(),
        ];
    }

    /**
     * @return Collection<int, array{
     *     part: string,
     *     current: string,
     *     unit: string,
     *     date: string,
     *     diff: float
     * }>
     */
    private function getLatestMeasurements(User $user): Collection
    {
        // Group by part, get latest for card display
        return $user->bodyPartMeasurements()
            ->orderBy('measured_at', 'desc')
            ->get()
            ->groupBy('part')
            ->map(function ($group): array {
                /** @var \App\Models\BodyPartMeasurement $latest */
                $latest = $group->first();
                /** @var \App\Models\BodyPartMeasurement|null $previous */
                $previous = $group->skip(1)->first();

                // 'value' is cast to decimal:2 (string) in model
                $currentValue = $latest->value;
                $previousValue = $previous ? $previous->value : 0;

                return [
                    'part' => $latest->part,
                    'current' => $currentValue,
                    'unit' => $latest->unit,
                    'date' => Carbon::parse($latest->measured_at)->format('Y-m-d'),
                    'diff' => $previous ? round((float) $currentValue - (float) $previousValue, 2) : 0,
                ];
            })->values();
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
