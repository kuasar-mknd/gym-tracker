<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\Date;

/**
 * Ramène au fuseau de l'application une date reçue avec un décalage.
 *
 * Une valeur comme `2026-10-04T22:30:00.000Z` désigne un instant. La règle
 * `date` l'accepte, mais le cast `datetime` d'Eloquent n'en écrit que l'heure
 * murale, dans le fuseau du décalage, et cette heure est relue ensuite comme
 * une heure de Paris : les réglages de séance, qui envoient l'heure en UTC,
 * reculaient la séance de deux heures à chaque enregistrement, et un verre
 * d'eau bu à 00 h 30 comptait pour la veille (#1952). Un champ qui ne garde
 * que le jour prenait de même le jour du décalage, ou partait en erreur.
 *
 * La conversion a lieu avant la validation, pour que les règles qui comparent
 * à aujourd'hui (`before_or_equal:today`, `after:today`) jugent la valeur que
 * l'application écrira. Seule une valeur qui porte un décalage ou un fuseau
 * change : une heure murale sans décalage reste une heure de l'application, et
 * une valeur illisible est laissée telle quelle à la règle `date`, qui la
 * refusera.
 *
 * `LesDatesRecuesSontRameneesAuFuseauTest` exige que chaque champ validé par
 * `date` dans une requête passe par l'une de ces deux méthodes.
 */
trait RameneLesDatesAuFuseauDeLApplication
{
    /**
     * Les champs qui désignent un instant : l'heure de l'application, à la seconde.
     *
     * @param  list<string>  $champs
     */
    protected function ramenerLesInstantsAuFuseauDeLApplication(array $champs): void
    {
        $this->ramenerAuFuseauDeLApplication($champs, 'Y-m-d H:i:s');
    }

    /**
     * Les champs qui ne gardent que le jour : le jour de l'instant, lu dans le
     * fuseau de l'application, comme `Carbon::today()` le borne partout ailleurs.
     *
     * @param  list<string>  $champs
     */
    protected function ramenerLesJoursAuFuseauDeLApplication(array $champs): void
    {
        $this->ramenerAuFuseauDeLApplication($champs, 'Y-m-d');
    }

    /**
     * @param  list<string>  $champs
     */
    private function ramenerAuFuseauDeLApplication(array $champs, string $format): void
    {
        $ramenees = [];

        foreach ($champs as $champ) {
            $valeur = $this->input($champ);

            if (! is_string($valeur) || ! self::porteUnDecalage($valeur)) {
                continue;
            }

            try {
                $ramenees[$champ] = Date::parse($valeur)
                    ->setTimezone(config()->string('app.timezone'))
                    ->format($format);
            } catch (InvalidFormatException) {
                continue;
            }
        }

        if ($ramenees !== []) {
            $this->merge($ramenees);
        }
    }

    /**
     * Vrai quand la valeur précise son fuseau : `Z`, un décalage (`+02:00`,
     * `-0400`) ou un nom de fuseau. `date_parse()` est l'analyseur même de la
     * règle `date`, qui accepte donc exactement ce qu'il reconnaît.
     */
    private static function porteUnDecalage(string $valeur): bool
    {
        return (date_parse($valeur)['is_localtime'] ?? false) === true;
    }
}
