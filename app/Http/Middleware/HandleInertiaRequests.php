<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Le gabarit racine, chargé à la première visite.
     *
     * @var string
     */
    #[\Override]
    protected $rootView = 'app';

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
     * @return array<string, bool>
     */
    private function configuredSocialProviders(): array
    {
        return collect(['google', 'github', 'apple'])
            ->mapWithKeys(fn (string $provider): array => [
                $provider => filled(config("services.{$provider}.client_id"))
                    && filled(config("services.{$provider}.client_secret")),
            ])
            ->all();
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
