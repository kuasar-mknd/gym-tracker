<?php

declare(strict_types=1);

namespace App\Filament\Sauvegardes;

use ShuvroRoy\FilamentSpatieLaravelBackup\Components\BackupDestinationStatusListRecords;

/**
 * Le tableau d'état du greffon (nombre d'archives, la plus récente, place
 * occupée), qui ne lit le disque qu'après avoir vu le dossier répondre (#1929).
 */
final class EtatDesSauvegardes extends BackupDestinationStatusListRecords
{
    use NeListeQueSiLeDossierRepond;
}
