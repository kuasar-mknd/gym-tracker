<?php

declare(strict_types=1);

namespace App\DTOs\Stats;

final readonly class MuscleDistributionStat
{
    public function __construct(
        public string $category,
        public float $volume
    ) {
    }
}
