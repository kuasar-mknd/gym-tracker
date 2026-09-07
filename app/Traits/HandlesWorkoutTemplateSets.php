<?php

declare(strict_types=1);

namespace App\Traits;

trait HandlesWorkoutTemplateSets
{
    /**
     * @param  array<int, array<string, mixed>>  $donneesDesSeries
     * @param  array<int, array{reps?: int|null, weight?: float|null, is_warmup?: bool}>  $sets
     */
    private function appendSetsData(array &$donneesDesSeries, array $sets, int $lineId, string $now): void
    {
        foreach ($sets as $setIndex => $set) {
            $donneesDesSeries[] = [
                'workout_template_line_id' => $lineId,
                'reps' => $set['reps'] ?? null,
                'weight' => $set['weight'] ?? null,
                'is_warmup' => $set['is_warmup'] ?? false,
                'order' => $setIndex,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
    }

    /** @param array<int, array<string, mixed>> $donneesDesSeries */
    private function insertSetsData(array $donneesDesSeries): void
    {
        if ($donneesDesSeries === []) {
            return;
        }

        // Chunking to avoid parameter limits in SQL (SQLite max is 999 typically)
        foreach (array_chunk($donneesDesSeries, 100) as $chunk) {
            \App\Models\WorkoutTemplateSet::insert($chunk);
        }
    }
}
