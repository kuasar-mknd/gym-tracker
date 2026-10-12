<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Jobs\EnvoyerLeLienDeReinitialisation;

class SendPasswordResetLinkAction
{
    /**
     * Met en file l'envoi du lien de réinitialisation, sans chercher le compte
     * (#1951).
     *
     * Ni la réponse, ni une erreur, ni le temps de la requête ne doivent dire si
     * l'adresse a un compte, ou si sa dernière demande est trop récente : le
     * courtier ne tourne donc pas ici, mais dans `EnvoyerLeLienDeReinitialisation`,
     * hors de la requête.
     */
    public function execute(string $adresse): void
    {
        EnvoyerLeLienDeReinitialisation::dispatch($adresse, app()->getLocale());
    }
}
