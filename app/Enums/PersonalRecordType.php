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
}
