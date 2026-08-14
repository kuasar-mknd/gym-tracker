<?php

declare(strict_types=1);

namespace App\DTOs\Stats;

final readonly class VolumeComparison
{
    public function __construct(
        public float $current_volume,
        public float $previous_volume,
        public float $difference,
        /** Null quand la periode precedente est vide : il n'y a pas de comparaison a faire. */
        public ?float $percentage
    ) {
    }
}
