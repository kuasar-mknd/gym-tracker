<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthentifieLaSessionDuCompte;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Models\User;
use App\Support\RappelDeLAppareil;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LogicException;

class PasswordController extends Controller
{
    /**
     * Change le mot de passe, et avec lui ferme toutes les autres sessions du
     * compte (#1940).
     *
     * Les autres sessions tombent à leur requête suivante, par
     * `AuthentifieLaSessionDuCompte` : l'empreinte du mot de passe qu'elles
     * portent ne correspond plus. Leurs cookies « se souvenir de moi » aussi : la
     * garde vérifie la même empreinte avant de reconnecter par ce cookie.
     *
     * Le cookie « se souvenir de moi » de cet appareil se juge avant le
     * changement, contre l'empreinte du mot de passe d'avant
     * (`RappelDeLAppareil::vautPour()`), jamais sur sa seule présence.
     */
    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        /** @var array{password: string} $donneesValidees */
        $donneesValidees = $request->validated();
        $utilisateur = $this->user();
        $garde = self::gardeDesComptes();
        $seSouvenaitDeCetAppareil = RappelDeLAppareil::vautPour($request, $garde, $utilisateur);

        $utilisateur->update([
            'password' => $donneesValidees['password'],
        ]);

        self::rouvrirCetteSession($request, $garde, $utilisateur, $seSouvenaitDeCetAppareil);

        $request->viderLeCompteurDeTentatives();

        return back();
    }

    /**
     * La garde des comptes de l'application, qui tient la connexion en session.
     */
    private static function gardeDesComptes(): SessionGuard
    {
        $garde = Auth::guard(AuthentifieLaSessionDuCompte::GARDE);

        if (! $garde instanceof SessionGuard) {
            throw new LogicException('La garde des comptes tient sa connexion en session.');
        }

        return $garde;
    }

    /**
     * Rouvre la session qui a changé le mot de passe, sous le nouveau.
     *
     * `SessionGuard::login()` fait en un appel les trois choses qu'il faut, sans
     * hacher le mot de passe une seconde fois :
     *
     * - un nouvel identifiant de session, l'ancien détruit : la copie du cookie
     *   de CETTE session, prise sur l'appareil même où l'on change le mot de
     *   passe, ne rouvre plus rien. `AuthenticateSession` ne la fermerait pas,
     *   puisqu'elle porterait l'empreinte à jour ;
     * - l'empreinte du nouveau mot de passe en session (`password_hash_web`),
     *   que `AuthentifieLaSessionDuCompte` compare à chaque requête ;
     * - le cookie « se souvenir de moi » réémis avec cette empreinte, si
     *   l'appareil en portait un valide pour ce compte : l'ancien porte celle du
     *   mot de passe d'avant, et la garde le refuserait à l'expiration de la
     *   session. Un cookie périmé est effacé dans la même réponse.
     *
     * `Auth::logoutOtherDevices()` réémet le cookie, mais re-hache le mot de
     * passe qui vient de l'être, ne change pas l'identifiant de session, laisse
     * l'empreinte au middleware, et juge lui aussi le cookie sur sa seule
     * présence. L'événement `Login` part : c'est bien une connexion, sous le
     * nouveau mot de passe.
     */
    private static function rouvrirCetteSession(Request $request, SessionGuard $garde, User $utilisateur, bool $seSouvenirDeMoi): void
    {
        $garde->login($utilisateur, remember: $seSouvenirDeMoi);

        if (! $seSouvenirDeMoi && $request->cookies->has($garde->getRecallerName())) {
            $garde->getCookieJar()->queue($garde->getCookieJar()->forget($garde->getRecallerName()));
        }
    }
}
