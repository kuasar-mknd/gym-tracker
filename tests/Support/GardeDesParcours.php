<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Ce qu'un parcours Dusk doit vérifier avant d'écrire quoi que ce soit (#1909).
 *
 * `artisan dusk` démarre Laravel, qui charge le .env, puis lance Pest avec cet
 * environnement. PHPUnit ne remplace pas une variable déjà posée, et même
 * `force="true"` n'atteint pas `$_SERVER`, que Laravel lit en premier : les
 * réglages de phpunit.dusk.xml s'effacent donc devant le .env. Sous Sail, le
 * parcours visait ainsi la base de développement, et les parcours qui portent
 * `DatabaseTruncation` la vidaient au premier test (`migrate:fresh`). Le
 * navigateur, lui, tourne dans le conteneur `selenium` : `http://localhost`
 * y désigne ce conteneur, pas l'application.
 *
 * Les deux défauts se voient dans la configuration que lit le parcours. La
 * garde la juge, et refuse plutôt que de laisser un parcours détruire une base
 * qui n'est pas la sienne ou attendre trente secondes une page injoignable.
 */
final class GardeDesParcours
{
    /**
     * Le suffixe qui désigne une base que les parcours ont le droit de vider.
     */
    public const string SUFFIXE_DES_BASES = '_dusk';

    /**
     * Les raisons de refuser le parcours ; une liste vide le laisse partir.
     *
     * @return list<string>
     */
    public static function motifsDeRefus(string $base, string $urlDeLApplication, string $urlDuPilote): array
    {
        $motifs = [];

        if (! str_ends_with($base, self::SUFFIXE_DES_BASES)) {
            $motifs[] = sprintf(
                'La base « %s » n’est pas une base de parcours : son nom ne finit pas par %s. Les parcours y '
                .'créent leurs comptes, et ceux qui portent DatabaseTruncation la vident au premier test '
                .'(migrate:fresh). `artisan dusk` lit le .env ; sous Sail, il met .env.dusk.local à la place '
                .'du .env le temps de la passe : dérivez-le du .env entier avec DB_DATABASE=gym_tracker_dusk, '
                .'par la recette du README, « Parcours navigateur ».',
                $base,
                self::SUFFIXE_DES_BASES,
            );
        }

        $hoteDuPilote = self::hoteHttp($urlDuPilote);
        $hoteDeLApplication = self::hoteHttp($urlDeLApplication);

        if ($hoteDuPilote === null) {
            $motifs[] = sprintf(
                'DUSK_DRIVER_URL « %s » n’est pas une adresse http : impossible de savoir sur quelle machine '
                .'tourne le navigateur.',
                $urlDuPilote,
            );
        }

        if ($hoteDeLApplication === null) {
            $motifs[] = sprintf(
                'APP_URL « %s » n’est pas une adresse http que le navigateur puisse ouvrir.',
                $urlDeLApplication,
            );
        }

        if ($hoteDuPilote !== null && $hoteDeLApplication !== null
            && self::designeLaMachineQuiLeLit($hoteDeLApplication)
            && ! self::designeLaMachineQuiLeLit($hoteDuPilote)) {
            $motifs[] = sprintf(
                'APP_URL vise %s, mais le navigateur tourne sur %s (DUSK_DRIVER_URL) : de là-bas, %s désigne '
                .'sa propre machine, pas l’application. Sous Sail, APP_URL=http://laravel.test, dans le '
                .'.env.dusk.local que donne la recette du README, « Parcours navigateur ».',
                $hoteDeLApplication,
                $hoteDuPilote,
                $hoteDeLApplication,
            );
        }

        return $motifs;
    }

    /**
     * Refuse le parcours dès qu'un motif existe, en les citant tous.
     *
     * @throws RuntimeException
     */
    public static function verifier(string $base, string $urlDeLApplication, string $urlDuPilote): void
    {
        $motifs = self::motifsDeRefus($base, $urlDeLApplication, $urlDuPilote);

        if ($motifs === []) {
            return;
        }

        throw new RuntimeException(
            "Parcours refusé avant toute écriture en base (#1909) :\n- ".implode("\n- ", $motifs)
        );
    }

    /**
     * L'hôte d'une adresse http(s), en minuscules, ou null si l'adresse n'en est pas une.
     */
    private static function hoteHttp(string $url): ?string
    {
        $parties = parse_url($url);

        if (! is_array($parties) || ! isset($parties['scheme'], $parties['host'])) {
            return null;
        }

        if (! in_array(strtolower($parties['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return strtolower($parties['host']);
    }

    /**
     * Vrai pour un hôte qui désigne toujours la machine où on le résout :
     * localhost et ses sous-domaines, 127.0.0.0/8, ::1 et 0.0.0.0.
     */
    private static function designeLaMachineQuiLeLit(string $hote): bool
    {
        $hote = trim($hote, '[]');

        if ($hote === 'localhost' || str_ends_with($hote, '.localhost')) {
            return true;
        }

        if ($hote === '::1' || $hote === '0.0.0.0') {
            return true;
        }

        return filter_var($hote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($hote, '127.');
    }
}
