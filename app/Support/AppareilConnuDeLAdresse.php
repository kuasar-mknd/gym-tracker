<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Cet appareil porte-t-il le cookie (`AppareilConnu`) du compte que désigne
 * l'adresse saisie (#2310) ? Le compte du cookie doit répondre à la même clef
 * que l'adresse (casse, accents, forme du domaine), sinon le cookie vaut pour
 * un autre compte, et l'appareil reste soumis au plafond.
 */
final class AppareilConnuDeLAdresse
{
    public static function juger(Request $requete, string $adresseCanonique): bool
    {
        $compte = AppareilConnu::compteDe($requete);

        return $compte instanceof User
            && PlafondParAdresse::clef($compte->email) === PlafondParAdresse::clef($adresseCanonique)
            && AppareilConnu::vautPour($requete, $compte);
    }
}
