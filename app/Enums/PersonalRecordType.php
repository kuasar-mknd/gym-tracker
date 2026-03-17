<?php

declare(strict_types=1);

namespace App\Enums;

enum PersonalRecordType: string
{
    case MaxWeight = 'max_weight';
    case Max1RM = 'max_1rm';
    case MaxVolumeSet = 'max_volume_set';

    // Legacy/Test values
    case OneRM = '1RM';
    case Strength = 'strength';
    case Cardio = 'cardio';
    case Volume = 'volume';

    public function label(): string
    {
        return match ($this) {
            self::MaxWeight, self::Strength => 'Poids Max',
            self::Max1RM, self::OneRM => '1RM Estimé',
            self::MaxVolumeSet, self::Volume => 'Volume Max',
            self::Cardio => 'Cardio',
        };
    }
}
