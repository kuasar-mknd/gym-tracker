<?php

declare(strict_types=1);

namespace App\Services\Diagnostic;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * La période que lit le diagnostic, tirée d'une seule lecture de l'horloge
 * (`.ai/rules/horloge.md`) : de `depuis` à `jusqua`, dans le fuseau de
 * l'application.
 *
 * Elle ne remonte jamais au-delà de trente jours, la conservation des
 * sources, et vaut vingt-quatre heures quand rien n'est demandé.
 */
final readonly class FenetreDeDiagnostic
{
    public const int JOURS_AU_PLUS = 30;

    public const int HEURES_PAR_DEFAUT = 24;

    public const string INSTANT_ATTENDU = '« depuis » attend un instant ISO 8601, par exemple 2026-10-11T08:00:00+02:00.';

    /** Un instant ISO 8601 : une date, une heure facultative de 00:00 à 23:59:59, un décalage facultatif. */
    private const string INSTANT_ISO_8601 = '/^(?<annee>\d{4})-(?<mois>\d{2})-(?<jour>\d{2})(?:[T ](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?)?(?:Z|[+-]\d{2}:?\d{2})?$/D';

    public function __construct(
        public CarbonImmutable $depuis,
        public CarbonImmutable $jusqua,
    ) {
    }

    /**
     * Depuis l'instant saisi, ramené au fuseau de l'application et borné aux
     * trente derniers jours ; les vingt-quatre dernières heures sans saisie.
     *
     * @throws InvalidArgumentException si la saisie n'est pas un instant ISO 8601
     */
    public static function depuis(?string $saisie, CarbonInterface $maintenant): self
    {
        $jusqua = self::dansLeFuseau($maintenant);
        $depuis = match ($saisie) {
            null, '' => $jusqua->subHours(self::HEURES_PAR_DEFAUT),
            default => self::dansLeFuseau(self::instantSaisi($saisie)),
        };

        return new self($depuis->max($jusqua->subDays(self::JOURS_AU_PLUS))->min($jusqua), $jusqua);
    }

    /**
     * Les `$jours` derniers jours, de un à trente.
     */
    public static function jours(int $jours, CarbonInterface $maintenant): self
    {
        $jusqua = self::dansLeFuseau($maintenant);

        return new self($jusqua->subDays(min(self::JOURS_AU_PLUS, max(1, $jours))), $jusqua);
    }

    /**
     * L'instant saisi, s'il a la forme ISO 8601 et désigne un instant qui
     * existe.
     */
    private static function instantSaisi(string $saisie): CarbonImmutable
    {
        $instant = match (self::existe($saisie)) {
            true => rescue(static fn (): CarbonImmutable => CarbonImmutable::parse($saisie), null, report: false),
            false => null,
        };

        return $instant ?? throw new InvalidArgumentException(self::INSTANT_ATTENDU);
    }

    /**
     * La saisie a-t-elle la forme ISO 8601, chaque champ dans ses bornes ?
     * Carbon reporte ce qui déborde au lieu de le refuser : un 31 septembre
     * devient le 1er octobre, un 29 février d'une année commune le 1er mars,
     * une heure 24 le lendemain. La date est donc vérifiée avant lui
     * (`checkdate()`), l'heure par la forme.
     */
    private static function existe(string $saisie): bool
    {
        return preg_match(self::INSTANT_ISO_8601, $saisie, $champs) === 1
            && checkdate((int) $champs['mois'], (int) $champs['jour'], (int) $champs['annee']);
    }

    private static function dansLeFuseau(CarbonInterface $instant): CarbonImmutable
    {
        return $instant->toImmutable()->setTimezone(config()->string('app.timezone'));
    }
}
