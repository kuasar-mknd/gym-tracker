<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\NotificationService;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * Ce que le navigateur peut garder d'une réponse faite à un compte connecté.
     *
     * `no-store` : ni le cache HTTP ni, dans la plupart des navigateurs, la
     * mémoire de retour arrière (bfcache) ne la gardent, ou celle-ci l'évince
     * quand les cookies changent, à la déconnexion. De la même longueur que
     * le `no-cache, private` qu'elle remplace : le budget d'en-têtes de
     * `EnTetesDeReponseTest` n'y perd rien.
     */
    public const string CACHE_D_UNE_PAGE_DE_COMPTE = 'no-store, private';

    /**
     * La clé de session qui retient à qui la session a servi sa dernière page :
     * un compte, par son identifiant, ou un invité.
     */
    private const string TITULAIRE_DE_L_HISTORIQUE = 'historique.titulaire';

    /**
     * Le titulaire d'une session sans compte.
     */
    private const string INVITE = 'invite';

    /**
     * Le gabarit racine, chargé à la première visite.
     *
     * @var string
     */
    #[\Override]
    protected $rootView = 'app';

    /**
     * Ce que le navigateur garde des pages d'un compte, une fois parti (#1965).
     *
     * Inertia range les props de chaque page visitée dans l'historique du
     * navigateur et les rend au bouton Retour sans rien demander au serveur :
     * après une déconnexion, sur un appareil partagé, la personne suivante
     * revoyait le journal, les mesures ou l'adresse du compte parti. Une page
     * de compte voyage donc avec `encryptHistory` : Inertia chiffre ce qu'il
     * range, avec une clé qu'il garde dans le `sessionStorage` de l'onglet.
     * `clearHistory` jette la clé : une entrée restée dans l'historique ne se
     * déchiffre plus, et Inertia redemande la page au serveur, qui renvoie
     * vers la connexion. Voir `jeterLaCleQuandLeTitulaireChange()`, et, pour
     * les autres onglets, resources/js/Utils/historiqueDuCompte.js.
     *
     * La réponse elle-même sort en `no-store` : le document complet d'une page
     * de compte porte aussi ses props, et le cache HTTP le resservirait tel quel
     * à une navigation arrière qui quitte le document courant.
     *
     * Le chiffrement et le cache se décident ici, à chaque requête, et jamais
     * ailleurs : `ResponseFactory` est un singleton, une valeur posée une fois
     * resterait pour la requête suivante du même processus, celle d'un invité
     * comprise.
     */
    #[\Override]
    public function handle(Request $request, Closure $next): Response
    {
        $compte = $request->user();
        $pageDUnCompte = $compte !== null;

        $this->jeterLaCleQuandLeTitulaireChange($request, $compte instanceof Authenticatable ? $compte : null);

        Inertia::encryptHistory($pageDUnCompte && $this->leNavigateurPeutChiffrer($request));

        $reponse = parent::handle($request, $next);

        if ($pageDUnCompte) {
            $reponse->headers->set('Cache-Control', self::CACHE_D_UNE_PAGE_DE_COMPTE);
        }

        return $reponse;
    }

    /**
     * Pose `clearHistory` dès que la session ne sert plus le titulaire qu'elle
     * servait : un autre compte, un invité, ou personne encore (#1965).
     *
     * La clé de l'historique vit dans l'onglet, la session sur le serveur : la
     * seule chose que le serveur sache, c'est à qui il sert la page. Quand ce
     * n'est plus le titulaire de la page précédente, la page suivante dit au
     * client de jeter la clé. Cela couvre chaque fin de session, et pas
     * seulement celles qui passent par un contrôleur : la déconnexion et la
     * suppression du compte, qui la posent aussi elles-mêmes, mais encore un
     * mot de passe changé depuis un autre appareil, qui vide la session
     * (`AuthentifieLaSessionDuCompte`), un compte supprimé depuis le panneau,
     * une session expirée, même suivie d'une page publique. Et chaque début :
     * un compte qui se connecte, par n'importe quel chemin.
     *
     * La consigne ne vaut que pour l'onglet qui reçoit cette page : la session
     * sert tous les onglets du navigateur, et chacun a sa clé. Les autres
     * onglets la jettent eux-mêmes, d'après le titulaire que chaque page
     * déclare dans `auth.user` (resources/js/Utils/historiqueDuCompte.js).
     *
     * Une session neuve n'a pas de titulaire : sa première page jette la clé,
     * sans effet quand il n'y en avait pas. La consigne passe par la session,
     * et une réponse qui ne rend pas de page la laisse à la suivante. Le même
     * compte, lui, garde sa clé d'une page à l'autre.
     */
    private function jeterLaCleQuandLeTitulaireChange(Request $request, ?Authenticatable $compte): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $titulaire = $compte === null ? self::INVITE : 'compte:'.self::identifiantDe($compte);
        $session = $request->session();

        if ($session->get(self::TITULAIRE_DE_L_HISTORIQUE) === $titulaire) {
            return;
        }

        $session->put(self::TITULAIRE_DE_L_HISTORIQUE, $titulaire);

        Inertia::clearHistory();
    }

    /**
     * L'identifiant du compte, en texte.
     */
    private static function identifiantDe(Authenticatable $compte): string
    {
        $identifiant = $compte->getAuthIdentifier();

        return is_int($identifiant) || is_string($identifiant) ? (string) $identifiant : '';
    }

    /**
     * Si le navigateur offre `crypto.subtle`, sans quoi Inertia ne chiffre rien.
     *
     * Il ne l'offre qu'à un contexte sûr : une page servie en HTTPS, ou depuis
     * la boucle locale (`localhost`, `*.localhost`, 127.0.0.0/8, ::1). Ailleurs,
     * Inertia ne sait pas créer sa clé et lève à la première page : l'écran
     * resterait blanc. C'est le cas des parcours navigateur sous Sail, servis
     * en http sur `laravel.test` ; la production, derrière le proxy inverse
     * HTTPS qui transmet `X-Forwarded-Proto`, chiffre.
     */
    private function leNavigateurPeutChiffrer(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        $hote = trim($request->getHost(), '[]');

        return $hote === 'localhost'
            || str_ends_with($hote, '.localhost')
            || IpUtils::checkIp($hote, ['127.0.0.0/8', '::1']);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $this->getUserData($request),
            ],
            'flash' => [
                'success' => $request->hasSession() ? $request->session()->get('success') : null,
                'error' => $request->hasSession() ? $request->session()->get('error') : null,
            ],
            // La CI construit son environnement Dusk avec APP_ENV=testing, mais
            // .env.dusk.local garde APP_ENV=local : le seul test d'environnement
            // tenait donc en CI et lâchait sans bruit en local — où l'overlay de
            // célébration que ce drapeau existe pour taire se déclenchait en
            // plein test et avalait les clics. Le drapeau explicite met les deux
            // exécutions d'accord.
            'is_testing' => app()->environment('testing') || config('app.running_browser_tests') === true,
            // Accompagne la route /__dev-login. Le serveur est le
            // seul à savoir qu'il est en local : import.meta.env.DEV vaut false
            // dans un build de production, que le serveur local sert quand même.
            'is_local' => app()->environment('local'),
            'social_login_enabled' => $this->configuredSocialProviders(),
            'pending_migrations' => $this->pendingMigrationCount(),
            'vapidPublicKey' => config('webpush.vapid.public_key'),
        ];
    }

    /**
     * Les connexions sociales réellement utilisables, par fournisseur.
     *
     * La page de connexion le demandait déjà — `social_login_enabled?.apple ??
     * true` — et personne ne l'envoyait : le `?? true` gagnait à chaque fois et
     * les trois boutons s'affichaient sans condition. Apple répondait alors 500
     * à chaque clic, le paquet n'ayant jamais été branché sur Socialite et
     * aucun identifiant n'étant renseigné non plus.
     *
     * Un fournisseur ne compte comme utilisable qu'avec les deux moitiés de son
     * identité OAuth : un client id sans secret ne peut pas mener l'échange à
     * son terme, et proposer le bouton quand même envoie l'utilisateur sur une
     * page d'erreur au lieu de chez Apple.
     *
     * Apple a deux façons de compléter son identité (#1911) : un secret signé
     * à la main (`client_secret`), ou le trio qui le signe à chaque échange
     * (`team_id`, `key_id`, `private_key`). L'un ou l'autre suffit. Un trio
     * incomplet ne signe rien : config/services.php ne transmet la clé privée
     * qu'avec les deux autres pièces, et le paquet présente alors le secret
     * posé tel quel, s'il y en a un.
     *
     * @return array<string, bool>
     */
    private function configuredSocialProviders(): array
    {
        return collect(['google', 'github', 'apple'])
            ->mapWithKeys(fn (string $fournisseur): array => [
                $fournisseur => filled(config("services.{$fournisseur}.client_id"))
                    && ($this->aUnSecretClient($fournisseur) || $this->peutSignerSonSecretClient($fournisseur)),
            ])
            ->all();
    }

    /**
     * Le secret client posé tel quel.
     */
    private function aUnSecretClient(string $fournisseur): bool
    {
        return filled(config("services.{$fournisseur}.client_secret"));
    }

    /**
     * Le trio qui signe le secret client à chaque échange, qu'Apple seul lit.
     */
    private function peutSignerSonSecretClient(string $fournisseur): bool
    {
        return $fournisseur === 'apple'
            && filled(config('services.apple.team_id'))
            && filled(config('services.apple.key_id'))
            && filled(config('services.apple.private_key'));
    }

    /**
     * Le nombre de migrations que la base n'a pas jouées, ou null hors local.
     *
     * Un schéma en retard sur le code échoue à l'écriture, pas à la lecture : la
     * page s'affiche, la requête part en 500 sur l'insertion, et le front annule
     * sa mise à jour optimiste. Toutes les écritures de l'application ont cassé
     * ainsi pendant un après-midi parce qu'il manquait à activity_log une
     * colonne ajoutée quelques jours plus tôt, sans que rien nulle part ne le
     * dise — la suite de tests ne peut pas le voir non plus, puisqu'elle migre
     * une base neuve à chaque exécution.
     *
     * Compté seulement en local, et seulement pour les requêtes qui vont rendre
     * une page : la production n'en paie jamais le prix.
     */
    private function pendingMigrationCount(): ?int
    {
        if (! app()->environment('local')) {
            return null;
        }

        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return null;
            }

            $ran = $migrator->getRepository()->getRan();

            $paths = $migrator->paths();
            $pending = $migrator->getMigrationFiles($paths === [] ? [database_path('migrations')] : $paths);

            return count(array_diff(array_keys($pending), $ran));
        } catch (\Throwable) {
            // Une base qui ne répond pas est un autre problème, plus bruyant.
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getUserData(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof \App\Models\User) {
            return null;
        }

        $notificationService = app(NotificationService::class);

        $latestAchievement = $notificationService->getLatestAchievement($user);

        $activeWorkout = app(\App\Services\ActiveWorkoutService::class)->for($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'unread_notifications_count' => $notificationService->getUnreadCount($user),
            'latest_achievement' => $latestAchievement instanceof \Illuminate\Notifications\DatabaseNotification ? [
                'id' => $latestAchievement->id,
                'data' => $latestAchievement->data,
                'created_at' => $latestAchievement->created_at,
            ] : null,
            /*
             * `Show.vue` lit `auth.user.default_rest_time` pour decider de la
             * duree du repos, avec un repli a 90 secondes. Ce champ n'etait pas
             * envoye : la lecture rendait `undefined`, le repli s'appliquait
             * toujours, et le reglage stocke n'avait aucun effet.
             *
             * Invisible tant qu'on ne l'a pas change, la colonne valant 90 par
             * defaut en base — donc exactement la valeur du repli.
             */
            'default_rest_time' => $user->default_rest_time,
            'auto_rest_timer' => $user->auto_rest_timer,
            'current_streak' => app(\App\Services\StreakService::class)->currentStreakFor($user),
            'longest_streak' => $user->longest_streak,
            'active_workout' => $activeWorkout,
        ];
    }
}
