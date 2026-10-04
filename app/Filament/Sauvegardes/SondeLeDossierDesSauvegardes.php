<?php

declare(strict_types=1);

namespace App\Filament\Sauvegardes;

use App\Support\Sante\DossierDesSauvegardesCheck;

/**
 * Ce que la page « Sauvegardes » et ses deux tableaux savent du dossier des
 * archives avant d'y toucher (#1929).
 *
 * Le greffon parcourt le disque sans délai : sur un partage qui ne répond
 * plus, l'appel attend sans fin et le travailleur d'Octane qui sert la page
 * reste pris. Le dossier est donc sondé d'abord par un sous-processus borné,
 * comme le fait la santé (`.ai/rules/providers.md`).
 */
trait SondeLeDossierDesSauvegardes
{
    /**
     * Au-delà, la page dit que le dossier ne répond pas plutôt que de faire
     * attendre qui l'a ouverte. La moitié du délai de la santé, qui décide,
     * elle, du rouge : la page ne fait que renoncer à lister.
     */
    public const int DELAI_DE_LA_SONDE_EN_SECONDES = 5;

    /**
     * Le verdict de la requête en cours. Livewire reconstruit le composant à
     * chaque requête : la sonde tourne une fois par requête, jamais plus.
     */
    private ?bool $dossierDesSauvegardesRepond = null;

    /**
     * Le dossier des archives a-t-il répondu dans le délai ?
     */
    protected function dossierDesSauvegardesRepond(): bool
    {
        return $this->dossierDesSauvegardesRepond ??= DossierDesSauvegardesCheck::repond(
            DossierDesSauvegardesCheck::dossierDesArchives(),
            self::DELAI_DE_LA_SONDE_EN_SECONDES,
        );
    }

    protected function titreDuDossierQuiNeRepondPas(): string
    {
        return 'Le dossier des sauvegardes ne répond pas';
    }

    protected function explicationDuDossierQuiNeRepondPas(): string
    {
        return sprintf(
            "Il n'a pas répondu en %d s : la page ne liste pas les archives et ne propose pas d'en créer, "
            .'pour ne pas attendre un partage qui ne rend plus la main. La page « Santé » le suit (« Dossier des sauvegardes ») ; '
            .'sur le serveur, vérifier que le partage BACKUP_HOST_PATH est monté et que la machine qui le sert répond.',
            self::DELAI_DE_LA_SONDE_EN_SECONDES,
        );
    }
}
