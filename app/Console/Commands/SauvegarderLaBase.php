<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Sauvegarde quotidienne de la base, chiffrée ou rien : sans mot de passe
 * d'archive, `backup:run` écrirait un dump en clair sur une autre machine.
 */
class SauvegarderLaBase extends Command
{
    #[\Override]
    protected $signature = 'sauvegarde:base';

    #[\Override]
    protected $description = 'Sauvegarde la base de données, chiffrée, sur le disque des sauvegardes';

    public function handle(): int
    {
        if (blank(config('backup.backup.password'))) {
            $this->error('BACKUP_ARCHIVE_PASSWORD est vide : aucune archive en clair ne sera écrite.');

            return self::FAILURE;
        }

        return $this->call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
    }
}
