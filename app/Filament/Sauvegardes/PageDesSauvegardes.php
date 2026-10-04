<?php

declare(strict_types=1);

namespace App\Filament\Sauvegardes;

use ShuvroRoy\FilamentSpatieLaravelBackup\Pages\Backups;

/**
 * La page « Sauvegardes » du greffon, déclarée par `usingPage()`, qui sonde le
 * dossier des archives avant de le lister (#1929).
 *
 * S'il ne répond pas dans le délai, elle le dit au lieu de rendre les deux
 * tableaux, et ne propose pas de créer une sauvegarde qui ne pourrait pas
 * s'écrire. Sinon, elle rend la page du greffon avec ses tableaux à elle, qui
 * sondent à leur tour à chaque rafraîchissement.
 */
final class PageDesSauvegardes extends Backups
{
    use SondeLeDossierDesSauvegardes;

    /**
     * L'adresse du greffon, que le menu, les liens et les gardes connaissent.
     */
    #[\Override]
    protected static ?string $slug = 'backups';

    #[\Override]
    protected string $view = 'filament.pages.sauvegardes';

    #[\Override]
    protected function getHeaderActions(): array
    {
        return $this->dossierDesSauvegardesRepond() ? parent::getHeaderActions() : [];
    }

    /**
     * @return array{dossierRepond: bool, titre: string, explication: string}
     */
    #[\Override]
    protected function getViewData(): array
    {
        return [
            'dossierRepond' => $this->dossierDesSauvegardesRepond(),
            'titre' => $this->titreDuDossierQuiNeRepondPas(),
            'explication' => $this->explicationDuDossierQuiNeRepondPas(),
        ];
    }
}
