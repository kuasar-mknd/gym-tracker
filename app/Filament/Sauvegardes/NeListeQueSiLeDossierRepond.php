<?php

declare(strict_types=1);

namespace App\Filament\Sauvegardes;

use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Un tableau du greffon qui ne lit le disque qu'après avoir vu le dossier
 * répondre (#1929). La page ne le rend que si le dossier répondait à son
 * ouverture, mais chaque tableau se rafraîchit seul toutes les minutes : le
 * partage peut s'endormir page ouverte, et chaque rafraîchissement aurait pris
 * un travailleur d'Octane. Le tableau reste alors vide et dit pourquoi.
 */
trait NeListeQueSiLeDossierRepond
{
    use SondeLeDossierDesSauvegardes;

    #[\Override]
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->emptyStateIcon(fn (): ?Heroicon => $this->dossierDesSauvegardesRepond() ? null : Heroicon::OutlinedExclamationTriangle)
            ->emptyStateHeading(fn (): ?string => $this->dossierDesSauvegardesRepond() ? null : $this->titreDuDossierQuiNeRepondPas())
            ->emptyStateDescription(fn (): ?string => $this->dossierDesSauvegardesRepond() ? null : $this->explicationDuDossierQuiNeRepondPas());
    }

    /**
     * Les archives, ou rien si le dossier ne répond pas : le greffon les lit
     * ici, par une liste du disque qui n'a pas de délai.
     *
     * @return Collection<array-key, mixed>|Paginator<array-key, mixed>|CursorPaginator<array-key, mixed>
     */
    #[\Override]
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        if (! $this->dossierDesSauvegardesRepond()) {
            return $this->cachedTableRecords = new Collection();
        }

        return parent::getTableRecords();
    }
}
