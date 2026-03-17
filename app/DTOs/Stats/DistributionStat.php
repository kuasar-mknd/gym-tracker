<?php

declare(strict_types=1);

namespace App\DTOs\Stats;

final readonly class DistributionStat
{
    public function __construct(
        public string $label,
        public int $count
    ) {
    }
}
