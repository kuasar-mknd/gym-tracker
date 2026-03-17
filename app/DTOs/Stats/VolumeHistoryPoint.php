<?php

declare(strict_types=1);

namespace App\DTOs\Stats;

final readonly class VolumeHistoryPoint
{
    public function __construct(
        public string $date,
        public float $volume,
        public string $name
    ) {
    }
}
