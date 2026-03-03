<?php

declare(strict_types=1);

namespace App\Traits;

trait CalculatesOneRepMax
{
    /**
     * Calculate the estimated 1-Rep Max using the Epley formula.
     */
    protected function calculate1RM(float|int|string $weight, float|int|string $reps): float
    {
        $weight = (float) $weight;
        $reps = (int) $reps;

        if ($reps <= 1) {
            return $weight;
        }

        // Epley Formula: 1RM = w * (1 + r / 30)
        return round($weight * (1 + $reps / 30), 2);
    }
}
