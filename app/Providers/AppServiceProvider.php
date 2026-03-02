<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\BodyMeasurement;
use App\Models\Set;
use App\Models\Workout;
use App\Services\AchievementService;
use App\Services\GoalService;
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
        if ($this->app->environment('testing')) {
            config(['telescope.enabled' => false]);
        }

        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
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

        Vite::prefetch(concurrency: 3);

        Model::shouldBeStrict(! $this->app->isProduction());

        Password::defaults(function () {
            $rule = Password::min(8);

            return $this->app->isProduction()
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

            app(AchievementService::class)->syncAchievements($user);
            app(GoalService::class)->syncGoals($user);
        });
    }

    private function registerWorkoutEvents(): void
    {
        Workout::saved(function (Workout $workout): void {
            // Streak is only updated when a workout is "finished" or has a date
            app(StreakService::class)->updateStreak($workout->user, $workout);

            app(AchievementService::class)->syncAchievements($workout->user);
            app(GoalService::class)->syncGoals($workout->user);
        });
    }

    private function registerMeasurementEvents(): void
    {
        BodyMeasurement::saved(fn (BodyMeasurement $bm) => app(GoalService::class)->syncGoals($bm->user));
        BodyMeasurement::deleted(fn (BodyMeasurement $bm) => app(GoalService::class)->syncGoals($bm->user));
    }
}
