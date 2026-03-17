<?php

declare(strict_types=1);

namespace App\Enums;

enum ExerciseCategory: string
{
    case Pectoraux = 'Pectoraux';
    case Epaules = 'Épaules';
    case Dos = 'Dos';
    case Jambes = 'Jambes';
    case Bras = 'Bras';
    case Abdominaux = 'Abdominaux';
    case Cardio = 'Cardio';

    // Legacy/Test values
    case Legs = 'Legs';
    case Test = 'Test';
    case A = 'A';

    public function label(): string
    {
        return match ($this) {
            self::Legs, self::Jambes => 'Jambes',
            default => $this->value,
        };
    }
}
