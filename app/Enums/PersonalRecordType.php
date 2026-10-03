<?php

declare(strict_types=1);

namespace App\Enums;

enum PersonalRecordType: string
{
    case MaxWeight = 'max_weight';
    case Max1RM = 'max_1rm';
    case MaxVolumeSet = 'max_volume_set';

    /**
     * Les quatre cas hérités : plus aucun code ne les écrit (#1811).
     *
     * Ils restent tant que la base de production n'a pas été vérifiée : un cas
     * retiré alors qu'une ligne le porte encore fait lever une ValueError à
     * chaque relecture par le modèle. `app:verify-data-coherence` compte ces
     * lignes chaque nuit.
     */
    case OneRM = '1RM';
    case Strength = 'strength';
    case Cardio = 'cardio';
    case Volume = 'volume';

    /**
     * Les valeurs des types que l'application tient : les seules que
     * `PersonalRecordService` écrit et reconstruit.
     *
     * La liste ne s'écrit qu'ici. Le service la parcourt, et le contrôle de
     * cohérence s'en sert pour compter les records d'un autre type : deux
     * définitions finiraient par diverger.
     *
     * @var list<string>
     */
    public const array SUIVIS = [
        self::MaxWeight->value,
        self::Max1RM->value,
        self::MaxVolumeSet->value,
    ];
}
