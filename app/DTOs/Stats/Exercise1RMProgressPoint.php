<?php

declare(strict_types=1);

namespace App\DTOs\Stats;

final readonly class Exercise1RMProgressPoint
{
    public function __construct(
        public string $date,
        public string $full_date,
        public float $one_rep_max
    ) {
    }
}
