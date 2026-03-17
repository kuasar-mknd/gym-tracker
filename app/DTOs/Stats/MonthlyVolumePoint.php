<?php

declare(strict_types=1);

namespace App\DTOs\Stats;

final readonly class MonthlyVolumePoint
{
    public function __construct(
        public string $month,
        public float $volume
    ) {
    }
}
