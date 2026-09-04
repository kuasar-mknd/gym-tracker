<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\BodyMeasurement;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Services\StreakService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use SocialiteProviders\Apple\Provider as AppleProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(\App\Services\GoalService::class);
        $this->app->singleton(\App\Services\AchievementService::class);
        $this->app->singleton(\App\Services\NotificationService::class);
        $this->app->singleton(\App\Services\PersonalRecordService::class);

        if (config('app.env') === 'testing') {
            config(['telescope.enabled' => false]);
        }

        if (config('app.env') === 'local' && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAppleSocialiteDriver();

        if (config('app.env') === 'testing') {
            Gate::define('viewPulse', fn ($user = null): bool => true);
        }

        Vite::useCspNonce();

        // Vite::prefetch is deliberately absent.
        //
        // It injected a <link rel="prefetch"> for every asset in the manifest, so
        // each page load pulled the entire build in the background. Measured on
        // /workouts: 141 asset requests totalling 1350 KB, of which 120 were
        // prefetches the page did not need, against the 21 it did.
        //
        // The service worker already precaches that same build — 131 entries,
        // 1.1 MB — so the prefetch fetched a second copy of what the app had
        // committed to caching anyway. On a phone on cellular data that is the
        // whole bundle, twice, on every page.
        //
        // What it bought was an instant first navigation to each page. In an
        // Inertia app a navigation needs only that page's chunk, tens of
        // kilobytes, so the trade was megabytes spent to save one small request —
        // and the service worker covers the repeat visits regardless.

        Model::shouldBeStrict(config('app.env') !== 'production');

        Password::defaults(function () {
            $rule = Password::min(8);

            return config('app.env') === 'production'
                ? $rule->mixedCase()->uncompromised()
                : $rule;
        });

        $this->registerSetEvents();
        $this->registerWorkoutEvents();
        $this->registerMeasurementEvents();

        \Illuminate\Support\Facades\Event::listen(function (\Illuminate\Notifications\Events\NotificationSent $event): void {
            if ($event->notifiable instanceof \App\Models\User) {
                app(\App\Services\NotificationService::class)->clearCache($event->notifiable, $event->notification::class);
            }
        });
    }

    private function registerSetEvents(): void
    {
        Set::saved(function (Set $set): void {
            $user = $set->workoutLine->workout->user;

            /*
             * Le test disait « renseigne » et signifiait « ni nul ni zero ».
             * Les deux cas doivent bien etre ecartes — une serie a zero kilo ou
             * zero repetition ne produit que des records nuls — mais il faut
             * l'ecrire, d'autant que `PersonalRecordService::shouldSkipSync()`
             * refait le meme controle a l'arrivee : cette garde-ci n'evite que
             * la mise en file d'un travail sans objet.
             */
            if ($set->weight !== null && $set->weight > 0.0 && $set->reps !== null && $set->reps > 0) {
                if (config('app.env') === 'testing' || config('database.connections.mysql.database') === 'gym_tracker_testing') {
                    \App\Jobs\SyncPersonalRecord::dispatchSync($set, $user);
                } else {
                    // ⚡ Bolt: Offload PR sync to background job
                    \App\Jobs\SyncPersonalRecord::dispatch($set, $user)->afterCommit();
                }
            }

            /**
             * A record only ever went up. Correcting a mistyped weight left the
             * inflated figure standing on the user's profile for good, because
             * nothing recomputed the record the corrected set was holding.
             */
            app(\App\Services\PersonalRecordService::class)->refreshRecordsHeldBy($set, $user);
            app(\App\Services\RecommendedValuesService::class)->invaliderPour((int) $user->id);

            // ⚡ Bolt: Offload heavy sync to background jobs
            \App\Jobs\SyncUserAchievements::dispatch($user);
            \App\Jobs\SyncUserGoals::dispatch($user);
        });

        Set::deleted(function (Set $set): void {
            $userId = $set->workoutLine?->workout?->user_id;

            if ($userId !== null) {
                app(\App\Services\RecommendedValuesService::class)->invaliderPour((int) $userId);
            }
        });

        // `personal_records.set_id` est ON DELETE SET NULL : apres coup, plus rien
        // ne dit ce que la serie detenait.
        Set::deleting(function (Set $set): void {
            app(\App\Services\PersonalRecordService::class)->retenirTypesDetenus($set);
        });

        Set::deleted(function (Set $set): void {
            $records = app(\App\Services\PersonalRecordService::class);
            $detenus = $records->typesRetenus($set);

            if ($detenus === []) {
                return;
            }

            $records->refreshFor($set, null, $detenus);
        });

        \App\Models\WorkoutLine::deleted(function (\App\Models\WorkoutLine $line): void {
            /**
             * Removing an exercise takes its sets with it through an ON DELETE
             * CASCADE, which fires no model events at all — so without this, a
             * record set during a session the user then deleted stood forever,
             * pointing at a row that no longer exists.
             */
            $user = $line->workout?->user;

            /*
             * Le `?->` ci-dessus n'est pas decoratif : pendant la suppression en
             * cascade d'une seance, la ligne parente peut avoir disparu — c'est
             * le defaut corrige en #1476. Le docblock du modele declare pourtant
             * `workout` non nul, ce qui faisait de ce test une entree de
             * baseline : c'est le docblock qui est optimiste, pas la garde.
             */
            if ($user instanceof User) {
                app(\App\Services\PersonalRecordService::class)->recompute($user, $line->exercise_id);
            }
        });
    }

    private function registerWorkoutEvents(): void
    {
        Workout::saved(function (Workout $workout): void {
            // Streak is only updated when a workout is "finished" or has a date
            if ($workout->wasRecentlyCreated || $workout->wasChanged('started_at')) {
                app(StreakService::class)->updateStreak($workout->user, $workout);
            }

            // ⚡ Bolt: Offload heavy sync to background jobs
            \App\Jobs\SyncUserAchievements::dispatch($workout->user);
            \App\Jobs\SyncUserGoals::dispatch($workout->user);
        });

        /**
         * Supprimer une seance recalcule la serie depuis ce qui reste.
         *
         * Rien ne le faisait : `Workout::deleting` relachait le volume,
         * `Workout::deleted` reconstruisait les records, et la serie restait
         * telle quelle. `last_workout_at` continuait de pointer une seance
         * disparue — or c'est la seule memoire du service, donc l'ecart
         * calcule a la seance SUIVANTE partait d'une date fantome et cassait
         * une serie pourtant continue. `longest_streak` n'etait jamais revu a
         * la baisse non plus. C'est #1460.
         *
         * Reconstruction complete et non ajustement : c'est la seule facon
         * d'etre juste apres une suppression, qui peut retirer un jour au
         * milieu d'une suite aussi bien qu'a son extremite.
         */
        Workout::deleted(function (Workout $workout): void {
            $user = $workout->user;

            if ($user === null) {
                return;
            }

            app(StreakService::class)->recalculerDepuisLesFaits($user);
        });
    }

    private function registerMeasurementEvents(): void
    {
        // ⚡ Bolt: Offload heavy goal sync to background jobs
        BodyMeasurement::saved(fn (BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user));
        BodyMeasurement::deleted(fn (BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user));
    }

    /**
     * Teaches Socialite about Apple, which it does not ship.
     *
     * `socialiteproviders/apple` was in composer.json and nothing ever told
     * Socialite it was there, so `Socialite::driver('apple')` threw
     * "Driver [apple] not supported" — a 500 on the login page's third button,
     * with or without credentials configured. Community providers announce
     * themselves through this event; without a listener the package is inert.
     */
    private function registerAppleSocialiteDriver(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('apple', AppleProvider::class);
        });
    }
}
