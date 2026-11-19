<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Admin;
use App\Support\AssuranceDuSecondFacteur;
use Illuminate\Support\Facades\Log;

/**
 * Efface la double authentification d'un administrateur (#2353) : son secret et
 * ses codes de récupération. Le panneau l'exige de chaque page ; l'administrateur
 * qui s'y reconnecte avec son mot de passe est donc conduit à l'enrôlement d'un
 * nouveau secret avant toute autre page. C'est la sortie de l'administrateur qui
 * a perdu son application et ses codes de récupération, depuis la console.
 *
 * Retirer le secret révoque aussi les jetons de diagnostic qu'il avait créés
 * (`RevoqueLesJetonsDeDiagnostic`). Les sessions déjà ouvertes perdent la preuve
 * de leur second facteur (`AssuranceDuSecondFacteur`) : le panneau les ferme à
 * leur prochaine requête.
 */
final readonly class ReinitialiseLaDoubleAuthentificationAction
{
    /**
     * @return bool vrai si l'administrateur en avait une à effacer
     */
    public function execute(Admin $admin): bool
    {
        $enAvait = $admin->aLaDoubleAuthentification() || filled($admin->getAppAuthenticationRecoveryCodes());

        $admin->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ])->save();

        AssuranceDuSecondFacteur::fermerLesSessions($admin);

        if ($enAvait) {
            Log::notice('Double authentification d\'un administrateur réinitialisée.', ['admin_id' => $admin->id]);
        }

        return $enAvait;
    }
}
