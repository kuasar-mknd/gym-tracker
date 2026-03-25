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

    public function label(): string
    {
        return $this->value;
    }
}
