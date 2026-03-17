<?php

declare(strict_types=1);

namespace App\DTOs\Stats;

final readonly class LatestBodyMetrics
{
    public function __construct(
        public float|string|null $latest_weight,
        public float $weight_change,
        public float|string|null $latest_body_fat
    ) {
    }
}
