<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\BodyMeasurement;
use App\Models\Set;
use App\Models\Workout;
use App\Services\StreakService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(\App\Services\StatsService::class);
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
                app(\App\Services\NotificationService::class)->clearCache($event->notifiable);
            }
        });
    }

    private function registerSetEvents(): void
    {
        Set::saved(function (Set $set): void {
            $user = $set->workoutLine->workout->user;

            if ($set->weight && $set->reps) {
                if (config('app.env') === 'testing' || config('database.connections.mysql.database') === 'gym_tracker_testing') {
                    \App\Jobs\SyncPersonalRecord::dispatchSync($set, $user);
                } else {
                    // ⚡ Bolt: Offload PR sync to background job
                    \App\Jobs\SyncPersonalRecord::dispatch($set, $user);
                }
            }

            /**
             * A record only ever went up. Correcting a mistyped weight left the
             * inflated figure standing on the user's profile for good, because
             * nothing recomputed the record the corrected set was holding.
             */
            app(\App\Services\PersonalRecordService::class)->refreshRecordsHeldBy($set, $user);

            // ⚡ Bolt: Offload heavy sync to background jobs
            \App\Jobs\SyncUserAchievements::dispatch($user);
            \App\Jobs\SyncUserGoals::dispatch($user);
        });

        Set::deleted(function (Set $set): void {
            // Unconditional: the database resolves personal_records.set_id as the
            // row goes, so by now nothing still admits this set held a record.
            app(\App\Services\PersonalRecordService::class)->refreshFor($set);
        });

        \App\Models\WorkoutLine::deleted(function (\App\Models\WorkoutLine $line): void {
            /**
             * Removing an exercise takes its sets with it through an ON DELETE
             * CASCADE, which fires no model events at all — so without this, a
             * record set during a session the user then deleted stood forever,
             * pointing at a row that no longer exists.
             */
            $user = $line->workout?->user;

            if ($user && $line->exercise_id) {
                app(\App\Services\PersonalRecordService::class)->recompute($user, (int) $line->exercise_id);
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
    }

    private function registerMeasurementEvents(): void
    {
        // ⚡ Bolt: Offload heavy goal sync to background jobs
        BodyMeasurement::saved(fn (BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user));
        BodyMeasurement::deleted(fn (BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user));
    }
}
