<?php

declare(strict_types=1);

namespace App\DTOs\Stats;

final readonly class WeightHistoryPoint
{
    public function __construct(
        public string $date,
        public string $full_date,
        public float $weight
    ) {
    }
}
