<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * Déconnecte, à sa requête suivante, toute session d'un compte de l'application
 * dont le mot de passe a changé depuis (#1940).
 *
 * La connexion pose en session l'empreinte du mot de passe (`password_hash_web`,
 * un HMAC de son hachage), et le cookie « se souvenir de moi » en porte une
 * copie. `AuthenticateSession` les compare au mot de passe actuel à chaque
 * requête du groupe `web` : quand le mot de passe change, depuis le profil, par
 * la réinitialisation par courriel ou par tout autre chemin, les autres sessions
 * et les autres cookies « se souvenir de moi » ne valent plus rien. Avant, une
 * session volée survivait au changement du mot de passe, qui est justement le
 * geste de la personne qui s'en inquiète, et ouvrait Horizon au compte listé
 * dans `HORIZON_ALLOWED_EMAILS`.
 *
 * Une session refusée lève la même `AuthenticationException` qu'une session
 * expirée, avant `HandleInertiaRequests` (ordre de priorité de Laravel) : renvoi
 * vers `/login`, que la visite Inertia suit (303 pour un formulaire, par
 * `EnsureGetOnRedirect` d'Inertia), ou 401 en JSON. Le cookie « se souvenir de
 * moi » de l'appareil est effacé dans la même réponse, ce qui évite la boucle
 * entre `/login` et l'accueil.
 *
 * Seulement sur la garde `web`, celle des comptes de l'application. Pulse et le
 * lecteur de journaux mettent le groupe `web` dans leur pile, puis le
 * `Authenticate` de Filament fait de `admin` la garde par défaut : le
 * middleware de Laravel y vérifierait l'administrateur et le renverrait vers la
 * connexion de l'application au lieu de celle du panneau, alors que le
 * `AuthenticateSession` de Filament, qui suit, fait déjà cette vérification avec
 * le bon renvoi. Le panneau lui-même n'a pas le groupe `web`.
 *
 * Rien d'une requête à l'autre sous Octane : le rappel statique du parent
 * (`redirectUsing()`) n'est jamais posé.
 */
class AuthentifieLaSessionDuCompte extends AuthenticateSession
{
    /**
     * La garde des comptes de l'application.
     */
    public const string GARDE = 'web';

    /**
     * Vérifie la session de la garde `web`, et laisse passer la requête dont la
     * garde par défaut est une autre : celle du panneau, sur Pulse et le lecteur
     * de journaux.
     *
     * @param  Request  $request
     */
    #[\Override]
    public function handle($request, Closure $next): mixed
    {
        if ($this->auth->getDefaultDriver() !== self::GARDE) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    /**
     * Revérifie la session de la requête contre le compte tel que la base le
     * donne maintenant, et la ferme comme `handle()` ferme une session
     * périmée si elle ne tient plus : compte supprimé, ou empreinte qui ne
     * correspond plus au mot de passe.
     *
     * `handle()` ne vérifie la session qu'à l'entrée de la requête. Une
     * écriture qui ne doit pas survivre à la session, comme l'abonnement push
     * d'un appareil (`PushSubscriptionController`), relit le compte sous le
     * verrou de sa ligne et appelle cette méthode avant d'écrire : le mot de
     * passe a pu changer entre-temps.
     *
     * Un compte sans mot de passe n'a pas d'empreinte, et `handle()` ne
     * vérifie pas sa session : elle tient ici aussi.
     *
     * @throws AuthenticationException
     */
    public function fermerSiLaSessionNeTientPlus(Request $request, ?Authenticatable $compteEnBase): void
    {
        if (! $this->laSessionTientPour($request, $compteEnBase)) {
            $this->logout($request);
        }
    }

    /**
     * La comparaison de `handle()`, l'empreinte de la session contre le mot
     * de passe donné, sous ses deux formes (HMAC, ou hachage brut d'avant).
     */
    private function laSessionTientPour(Request $request, ?Authenticatable $compteEnBase): bool
    {
        if ($compteEnBase === null) {
            return false;
        }

        $motDePasse = $compteEnBase->getAuthPassword();

        if (! is_string($motDePasse) || $motDePasse === '') {
            return true;
        }

        $empreinte = $request->session()->get('password_hash_'.self::GARDE);

        return is_string($empreinte) && $this->validatePasswordHash($motDePasse, $empreinte);
    }
}
