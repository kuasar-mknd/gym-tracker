<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    #[\Override]
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
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
            // CI builds its Dusk env with APP_ENV=testing but .env.dusk.local
            // keeps APP_ENV=local, so the environment check alone held on CI and
            // silently did not locally — where the celebration overlay this flag
            // exists to suppress fired mid-test and swallowed clicks. The
            // explicit flag makes both runs agree.
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
     * Which social sign-ins are actually usable, by provider.
     *
     * The login page already asked for this — `social_login_enabled?.apple ??
     * true` — and nothing ever sent it, so the `?? true` won every time and all
     * three buttons rendered unconditionally. Apple then answered 500 on every
     * click, because the package was never wired to Socialite and no
     * credentials were set either.
     *
     * A provider counts as usable only with both halves of its OAuth identity;
     * a client id with no secret cannot complete the exchange, and offering the
     * button anyway sends the user to an error page instead of to Apple.
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
     * How many migrations the database has not run, or null outside local.
     *
     * A schema behind the code fails at the write, not at the read: the page
     * renders, the request 500s on the insert, and the frontend rolls its
     * optimistic update back. Every mutation in the app broke that way for an
     * afternoon because activity_log was missing a column added days earlier,
     * and nothing anywhere said so — the test suite cannot see it either, since
     * it migrates a fresh database every run.
     *
     * Counted only in local, and only for requests that will draw a page, so
     * production never pays for it.
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
            // A database that cannot answer is its own, louder problem.
            return null;
        }
    }

    /**
     * Get the shared user data.
     *
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
            'current_streak' => app(\App\Services\StreakService::class)->currentStreakFor($user),
            'longest_streak' => $user->longest_streak,
            'active_workout' => $activeWorkout,
        ];
    }
}
