<?php

declare(strict_types=1);

namespace App\DTOs\Stats;

final readonly class DailyVolumeTrendPoint
{
    public function __construct(
        public string $date,
        public string $day_name,
        public float $volume
    ) {
    }
}
