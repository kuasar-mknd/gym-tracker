<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * L'heure de l'appareil au départ d'une écriture, que la file hors ligne
 * envoie avec chacune (`X-Heure-De-L-Appareil`, `SyncService.js`) (#2049).
 *
 * « Séance », « Lancer » et « Terminer » prennent l'heure du geste sur
 * l'appareil (`started_at`, `ended_at`) : rejouée des heures plus tard,
 * l'écriture garde l'heure où la personne a appuyé. Mais l'horloge de
 * l'appareil peut retarder ou avancer de quelques heures, et l'instant lu tel
 * quel faisait commencer la séance trois heures trop tôt sur un appareil en
 * retard de trois heures, ou terminait à son début, d'une durée nulle, une
 * séance démarrée ailleurs. Le serveur n'en garde donc que l'âge au départ de
 * la requête, lu sur la seule horloge de l'appareil (l'heure au départ moins
 * l'instant du geste), et le place sur la sienne (`Workout::debutBorne()`,
 * `Workout::finBornee()`) : le décalage s'annule, en ligne comme au rejeu. La
 * file lit l'heure au départ à chaque envoi, la tentative directe comme
 * chaque rejeu, et ne la range jamais avec l'écriture.
 *
 * Reste l'horloge remise à l'heure entre le geste et l'envoi : l'âge prend
 * alors la correction, que les bornes de `Workout` tiennent.
 */
final class HeureDeLAppareil
{
    /**
     * L'en-tête qui porte l'heure de l'appareil au départ de la requête :
     * `ENTETE_DE_L_HEURE_DE_L_APPAREIL` de resources/js/Utils/SyncService.js,
     * que `HorlogeDeLAppareilDecaleeTest` compare.
     */
    public const string ENTETE = 'X-Heure-De-L-Appareil';

    /**
     * La seule forme lue : une date et une heure du calendrier, suivies de
     * leur fuseau, comme `toISOString()` les écrit (`2026-10-21T16:00:00.000Z`),
     * ou avec un décalage (`2026-10-21T18:00:00+02:00`), sans rien avant ni
     * après. Une date suivie de mots (`+1 day`, `next monday`, `noon`) se lit
     * sans erreur, et Carbon en applique le sens : l'âge suivait les mots, et
     * deux décalages démesurés faisaient déborder la soustraction, en 500
     * avant même la validation (relu le 2026-10-21). L'année tient sur quatre
     * chiffres : l'âge reste loin des bornes d'un entier.
     */
    private const string FORME_D_UN_INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D';

    /**
     * L'âge, en secondes, de l'instant `$instant` au départ de la requête
     * (`$heureAuDepart`), tous deux lus sur l'horloge de l'appareil ; négatif
     * si l'instant suit le départ.
     *
     * Rien (null) quand l'un des deux manque, n'a pas la forme d'un instant
     * (`FORME_D_UN_INSTANT`), ou n'est pas une date du calendrier : sans
     * fuseau, Carbon les lirait dans celui du serveur, et une date en mots
     * (`now`) sur son horloge, celle que l'âge écarte. Le serveur prend alors
     * maintenant. Les deux se lisent
     * tels que l'appareil les envoie, avec leur décalage : ramenés à l'heure
     * murale de l'application, deux instants de l'heure que le passage à
     * l'heure d'hiver répète se confondraient.
     */
    public static function ageDe(mixed $instant, mixed $heureAuDepart): ?int
    {
        if (! self::estUnInstant($instant) || ! self::estUnInstant($heureAuDepart)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($heureAuDepart)->getTimestamp() - CarbonImmutable::parse($instant)->getTimestamp();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * Une date du calendrier qui dit son fuseau (`Z`, `+02:00`), dans la
     * forme que `toISOString()` écrit (`FORME_D_UN_INSTANT`), et rien d'autre.
     *
     * @phpstan-assert-if-true string $valeur
     */
    private static function estUnInstant(mixed $valeur): bool
    {
        if (! is_string($valeur) || preg_match(self::FORME_D_UN_INSTANT, $valeur) !== 1) {
            return false;
        }

        $analyse = date_parse($valeur);

        return $analyse['error_count'] === 0
            && is_int($analyse['year'])
            && is_int($analyse['month'])
            && is_int($analyse['day'])
            && checkdate($analyse['month'], $analyse['day'], $analyse['year'])
            && is_int($analyse['hour'])
            && ($analyse['is_localtime'] ?? false) === true;
    }
}
