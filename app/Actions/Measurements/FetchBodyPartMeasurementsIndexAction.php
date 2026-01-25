<?php

declare(strict_types=1);

namespace App\Actions\Measurements;

use App\Models\BodyPartMeasurement;
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
     *         diff: float|int
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
     *     diff: float|int
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
                /** @var BodyPartMeasurement $latest */
                $latest = $group->first();
                /** @var BodyPartMeasurement|null $previous */
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
