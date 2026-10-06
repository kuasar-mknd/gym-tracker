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
 * l'application écrira. Seule une date que la règle `date` accepte, et qui
 * porte un décalage ou un fuseau, change : une heure murale sans décalage reste
 * une heure de l'application, et une valeur que la règle refuse lui est
 * laissée telle quelle, pour qu'elle la refuse encore.
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

            if (! is_string($valeur) || ! self::estUneDateAvecDecalage($valeur)) {
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
     * Vrai quand la règle `date` accepte la valeur et qu'elle précise son
     * fuseau : `Z`, un décalage (`+02:00`, `-0400`) ou un nom de fuseau.
     *
     * Le test reprend celui de la règle : une lecture sans erreur (ce que
     * `strtotime()` y vérifie, et qui le fait rendre `false`), puis
     * `checkdate()` sur ce que lit `date_parse()`. Sans lui, la conversion
     * rendait valide ce que la règle refuse : Carbon reporte un 30 février au
     * 2 mars, et lit `now Z` comme l'instant présent.
     */
    private static function estUneDateAvecDecalage(string $valeur): bool
    {
        $analyse = date_parse($valeur);

        return $analyse['error_count'] === 0
            && is_int($analyse['year'])
            && is_int($analyse['month'])
            && is_int($analyse['day'])
            && checkdate($analyse['month'], $analyse['day'], $analyse['year'])
            && ($analyse['is_localtime'] ?? false) === true;
    }
}
