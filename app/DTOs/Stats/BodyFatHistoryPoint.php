<?php

declare(strict_types=1);

namespace App\DTOs\Stats;

final readonly class BodyFatHistoryPoint
{
    public function __construct(
        public string $date,
        public string $full_date,
        public float $body_fat
    ) {
    }
}
