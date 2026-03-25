<?php

declare(strict_types=1);

namespace App\Traits;

trait HandlesWorkoutTemplateSets
{
    /**
     * @param  array<int, array<string, mixed>>  $setsData
     * @param  array<int, array{reps?: int|null, weight?: float|null, is_warmup?: bool}>  $sets
     */
    private function appendSetsData(array &$setsData, array $sets, int $lineId, string $now): void
    {
        foreach ($sets as $setIndex => $set) {
            $setsData[] = [
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

    /** @param array<int, array<string, mixed>> $setsData */
    private function insertSetsData(array $setsData): void
    {
        if ($setsData === []) {
            return;
        }

        // Chunking to avoid parameter limits in SQL (SQLite max is 999 typically)
        foreach (array_chunk($setsData, 100) as $chunk) {
            \App\Models\WorkoutTemplateSet::insert($chunk);
        }
    }
}
