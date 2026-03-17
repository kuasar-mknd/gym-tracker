<?php

declare(strict_types=1);

namespace App\DTOs\Stats;

final readonly class DurationHistoryPoint
{
    public function __construct(
        public string $date,
        public int $duration,
        public string $name
    ) {
    }
}
