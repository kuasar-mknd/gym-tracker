<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\BodyMeasurement;
use App\Models\Set;
use App\Models\Workout;
use App\Services\PersonalRecordService;
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
    public function register(): void
    {
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
        if ($this->app->environment('testing')) {
            Gate::define('viewPulse', fn ($user = null): bool => true);
        }

        Vite::useCspNonce();
        Vite::prefetch(concurrency: 3);

        Model::shouldBeStrict(! app()->environment('production'));

        Password::defaults(function () {
            $rule = Password::min(8);

            return app()->environment('production')
                ? $rule->mixedCase()->uncompromised()
                : $rule;
        });

        $this->registerSetEvents();
        $this->registerWorkoutEvents();
        $this->registerMeasurementEvents();
    }

    private function registerSetEvents(): void
    {
        Set::saved(function (Set $set): void {
            $user = $set->workoutLine->workout->user;

            if ($set->weight && $set->reps) {
                app(PersonalRecordService::class)->syncSetPRs($set, $user);
            }

            // ⚡ Bolt: Offload heavy sync to background jobs
            \App\Jobs\SyncUserAchievements::dispatch($user);
            \App\Jobs\SyncUserGoals::dispatch($user);
        });
    }

    private function registerWorkoutEvents(): void
    {
        Workout::saved(function (Workout $workout): void {
            // Streak is only updated when a workout is "finished" or has a date
            app(StreakService::class)->updateStreak($workout->user, $workout);

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
