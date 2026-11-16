<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;

/**
 * L'authentification par application du panneau, avec ses codes de récupération
 * (#2353).
 *
 * Les administrateurs enrôlés avant leur activation ont un secret et aucun code :
 * Filament lève une `LogicException` (500) quand l'un d'eux, à la connexion,
 * choisit « utiliser un code de récupération ». Sans code rangé, aucun ne peut
 * être bon : le champ le dit comme pour un code faux, et l'administrateur
 * génère les siens depuis son profil.
 */
final class AuthentificationParApplication extends AppAuthentication
{
    /**
     * @return array<string>
     */
    #[\Override]
    public function getRecoveryCodes(HasAppAuthenticationRecovery $user): array
    {
        return $user->getAppAuthenticationRecoveryCodes() ?? [];
    }
}
