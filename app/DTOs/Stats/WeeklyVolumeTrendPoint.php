<?php

declare(strict_types=1);

namespace App\DTOs\Stats;

final readonly class WeeklyVolumeTrendPoint
{
    public function __construct(
        public string $date,
        public string $day_label,
        public float $volume
    ) {
    }
}
