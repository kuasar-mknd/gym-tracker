<?php

declare(strict_types=1);

namespace App\Filament\Sauvegardes;

use ShuvroRoy\FilamentSpatieLaravelBackup\Components\BackupDestinationListRecords;

/**
 * Le tableau des archives du greffon, qui ne liste le disque qu'après avoir vu
 * le dossier répondre (#1929).
 */
final class ListeDesSauvegardes extends BackupDestinationListRecords
{
    use NeListeQueSiLeDossierRepond;
}
