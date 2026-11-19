<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\ReinitialiseLaDoubleAuthentificationAction;
use App\Models\Admin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Efface la double authentification d'un administrateur du panneau (#2353),
 * quand il a perdu son application et ses codes de récupération : à sa
 * prochaine connexion, le panneau lui fait enrôler un nouveau secret.
 */
#[Signature('admin:reinitialiser-mfa {email : L\'adresse de l\'administrateur}')]
#[Description('Efface la double authentification d\'un administrateur du panneau')]
final class ReinitialiserLaDoubleAuthentification extends Command
{
    public function handle(ReinitialiseLaDoubleAuthentificationAction $reinitialisation): int
    {
        $email = $this->argument('email');

        $admin = is_string($email) ? Admin::query()->where('email', $email)->first() : null;

        if (! $admin instanceof Admin) {
            $this->components->error('Aucun administrateur n\'a cette adresse.');

            return self::FAILURE;
        }

        $enAvait = $reinitialisation->execute($admin);

        $this->components->info($enAvait
            ? 'Double authentification effacée : le panneau demandera un nouvel enrôlement à la prochaine connexion.'
            : 'Cet administrateur n\'avait pas de double authentification : rien à effacer.');

        return self::SUCCESS;
    }
}
