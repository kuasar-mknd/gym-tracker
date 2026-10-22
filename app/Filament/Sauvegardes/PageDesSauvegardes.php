<?php

declare(strict_types=1);

namespace App\Filament\Sauvegardes;

use App\Models\Admin;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Support\Arr;
use ShuvroRoy\FilamentSpatieLaravelBackup\Enums\BackupType;
use ShuvroRoy\FilamentSpatieLaravelBackup\Pages\Backups;

/**
 * La page « Sauvegardes » du greffon, déclarée par `usingPage()`, qui sonde le
 * dossier des archives avant de le lister ou d'en servir une archive (#1929).
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

    /**
     * Les maillons de la route de la page, que le greffon reprend pour sa
     * route de téléchargement (`FilamentSpatieLaravelBackupPlugin::register()`),
     * plus la sonde du dossier, qui ne vaut que pour celle-ci
     * (`NeTelechargeQueSiLeDossierRepond`).
     *
     * @return array<string>
     */
    #[\Override]
    public static function getRouteMiddleware(Panel $panel): array
    {
        return [...Arr::wrap(parent::getRouteMiddleware($panel)), NeTelechargeQueSiLeDossierRepond::class];
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return $this->dossierDesSauvegardesRepond() ? parent::getHeaderActions() : [];
    }

    /**
     * Met une sauvegarde en file, pour qui peut en créer et si le dossier
     * répond.
     *
     * Le greffon ne garde que son bouton : `create()` est une méthode Livewire
     * publique, qu'une requête forgée appelle sans lui. Un administrateur qui ne
     * pouvait que télécharger mettait ainsi une sauvegarde en file. Et la page
     * qui ne propose plus d'en créer quand le dossier se tait reste ouverte
     * ailleurs depuis qu'il répondait : la sauvegarde serait allée attendre le
     * partage dans un travailleur de la file.
     */
    #[\Override]
    public function create(string $type = BackupType::DATABASE_AND_FILES->value): void
    {
        $administrateur = auth('admin')->user();

        abort_unless($administrateur instanceof Admin && $administrateur->can('create-backup'), 403);

        if (! $this->dossierDesSauvegardesRepond()) {
            Notification::make()
                ->title($this->titreDuDossierQuiNeRepondPas())
                ->body($this->explicationDuDossierQuiNeRepondPas())
                ->danger()
                ->send();

            return;
        }

        parent::create($type);
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
