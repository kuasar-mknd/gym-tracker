<?php

declare(strict_types=1);

namespace App\Enums;

enum GoalType: string
{
    case Weight = 'weight';
    case Volume = 'volume';
    case Frequency = 'frequency';
    case Measurement = 'measurement';

    public function label(): string
    {
        return match ($this) {
            self::Weight => 'Poids',
            self::Volume => 'Volume',
            self::Frequency => 'Fréquence',
            self::Measurement => 'Mensuration',
        };
    }
}
