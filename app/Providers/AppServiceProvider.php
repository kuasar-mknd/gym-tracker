<?php

declare(strict_types=1);

namespace App\Providers;

use App\Jobs\SyncPersonalRecord;
use App\Jobs\SyncUserAchievements;
use App\Jobs\SyncUserGoals;
use App\Models\BodyMeasurement;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Services\AchievementService;
use App\Services\GoalService;
use App\Services\NotificationService;
use App\Services\PersonalRecordService;
use App\Services\StatsService;
use App\Services\StreakService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Telescope\TelescopeServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StatsService::class);
        $this->app->singleton(GoalService::class);
        $this->app->singleton(AchievementService::class);
        $this->app->singleton(NotificationService::class);
        $this->app->singleton(PersonalRecordService::class);

        if (config('app.env') === 'testing') {
            config(['telescope.enabled' => false]);
        }

        if (config('app.env') === 'local' && class_exists(TelescopeServiceProvider::class)) {
            $this->app->register(TelescopeServiceProvider::class);
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
        Vite::prefetch(concurrency: 3);

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

        Event::listen(function (NotificationSent $event): void {
            if ($event->notifiable instanceof User) {
                app(NotificationService::class)->clearCache($event->notifiable);
            }
        });
    }

    private function registerSetEvents(): void
    {
        Set::saved(function (Set $set): void {
            $user = $set->workoutLine->workout->user;

            if ($set->weight && $set->reps) {
                if (config('app.env') === 'testing' || config('database.connections.mysql.database') === 'gym_tracker_testing') {
                    SyncPersonalRecord::dispatchSync($set, $user);
                } else {
                    // ⚡ Bolt: Offload PR sync to background job
                    SyncPersonalRecord::dispatch($set, $user);
                }
            }

            // ⚡ Bolt: Offload heavy sync to background jobs
            SyncUserAchievements::dispatch($user);
            SyncUserGoals::dispatch($user);
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
            SyncUserAchievements::dispatch($workout->user);
            SyncUserGoals::dispatch($workout->user);
        });
    }

    private function registerMeasurementEvents(): void
    {
        // ⚡ Bolt: Offload heavy goal sync to background jobs
        BodyMeasurement::saved(fn (BodyMeasurement $bm) => SyncUserGoals::dispatch($bm->user));
        BodyMeasurement::deleted(fn (BodyMeasurement $bm) => SyncUserGoals::dispatch($bm->user));
    }
}
