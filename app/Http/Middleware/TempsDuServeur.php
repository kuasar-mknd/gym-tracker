<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TempsDuServeur\MesureDuTempsServeur;
use Closure;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dit à un utilisateur connecté ce que sa réponse a coûté au serveur (#1315).
 *
 * L'en-tête `Server-Timing` porte la durée de l'application, découpée en
 * trois étapes, et le nombre et la durée cumulée des requêtes SQL :
 *
 *     app;dur=48.2, routage;dur=1.3, controleur;dur=20.1,
 *     rendu;dur=26.8, sql;dur=12.4;desc="7 requetes"
 *
 * `routage` couvre la pile globale et le choix de la route ; `controleur`,
 * la pile de la route (session, connexion, liaison des modèles) et l'action ;
 * `rendu`, la préparation de la réponse — les props d'Inertia s'évaluent
 * là — et la sortie des piles. L'onglet réseau du navigateur l'affiche, et
 * `curl -I` ; environ 120 octets, sous le budget d'en-têtes que tient
 * `EnTetesDeReponseTest`.
 *
 * Coupé par défaut (`SERVER_TIMING_ENABLED`) : coupé, ce middleware ne fait
 * que lire la configuration et passer la main.
 *
 * Allumé, il ne mesure que pour qui est déjà connecté, à l'application
 * (garde `web`, l'API de la séance comprise) ou au panneau (garde `admin`).
 * Une durée et un nombre de requêtes diraient sinon si un compte existe :
 * vérifier un mot de passe coûte une requête et un hachage qu'une adresse
 * inconnue ne coûte pas. D'où aussi trois exclusions, même pour un connecté
 * — et une requête sans route n'est jamais mesurée :
 *
 * - toute requête qui a émis un événement d'authentification — essai
 *   d'identifiants, connexion, déconnexion, inscription, vérification. C'est ce
 *   qui couvre le formulaire de connexion du panneau, qui passe par une mise à
 *   jour Livewire, et la connexion réussie, dont l'utilisateur est connecté
 *   quand la réponse part ;
 * - toute route d'authentification : contrôleurs `App\Http\Controllers\Auth`,
 *   pages d'authentification du panneau, routes réservées aux invités. Un essai
 *   de mot de passe manqué n'émet aucun événement quand il passe par
 *   `validate()` ;
 * - toute réponse 404. #1418 et #1432 rendent « pas à vous » et « n'existe
 *   pas » indiscernables, en-têtes compris ; la ligne d'autrui coûte la
 *   vérification d'accès que l'absente ne coûte pas, et l'en-tête le dirait.
 *
 * En tête de la pile globale, pour mesurer tout ce qui suit, nonce compris.
 * L'utilisateur se lit au retour, par `hasUser()` : la garde dit ce que la
 * requête a déjà établi, sans rien aller chercher — `user()` irait lire le
 * cookie « se souvenir de moi », et connecterait.
 */
class TempsDuServeur
{
    private const array GARDES_MESUREES = ['web', 'admin'];

    private const array ESPACES_D_AUTHENTIFICATION = [
        'App\\Http\\Controllers\\Auth\\',
        'Filament\\Auth\\',
    ];

    /**
     * Le middleware `guest`, par son nom ou sa classe, paramètres ôtés.
     */
    private const array PORTES_DES_INVITES = ['guest', RedirectIfAuthenticated::class];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.temps_serveur') !== true) {
            return $next($request);
        }

        $mesure = app(MesureDuTempsServeur::class);
        $mesure->demarrer();

        $reponse = $next($request);

        if ($this->peutEtreMesuree($request->route(), $reponse, $mesure)) {
            $reponse->headers->set('Server-Timing', $mesure->valeurDeLEnTete());
        }

        return $reponse;
    }

    /**
     * Sans route — aucune trouvée, ou une réponse rendue avant le routage —,
     * rien ne dit ce que la requête a fait : elle n'est pas mesurée.
     */
    private function peutEtreMesuree(mixed $route, Response $reponse, MesureDuTempsServeur $mesure): bool
    {
        return $route instanceof Route
            && $reponse->getStatusCode() !== Response::HTTP_NOT_FOUND
            && ! $mesure->aVuUneAuthentification()
            && ! $this->estUneRouteDAuthentification($route)
            && $this->unUtilisateurEstConnecte();
    }

    private function estUneRouteDAuthentification(Route $route): bool
    {
        $controleur = (string) $route->getControllerClass();

        return Str::startsWith($controleur, self::ESPACES_D_AUTHENTIFICATION)
            || array_any($route->gatherMiddleware(), fn (mixed $middleware): bool => is_string($middleware)
                && in_array(Str::before($middleware, ':'), self::PORTES_DES_INVITES, true));
    }

    private function unUtilisateurEstConnecte(): bool
    {
        return array_any(self::GARDES_MESUREES, fn (string $garde): bool => auth()->guard($garde)->hasUser());
    }
}
