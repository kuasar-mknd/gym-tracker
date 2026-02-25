<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\BodyMeasurement;
use App\Models\Set;
use App\Models\Workout;
use App\Services\PersonalRecordService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Password::defaults(function () {
            $rule = Password::min(8);

            return $this->app->isProduction()
                ? $rule->mixedCase()->numbers()->symbols()->uncompromised()
                : $rule;
        });

        $this->configureGates();
        $this->configureVite();
        $this->configureSocialite();
        $this->configureModelHooks();
        $this->configureRateLimiters();
    }

    private function configureGates(): void
    {
        Gate::before(function ($user): ?true {
            $role = config('filament-shield.super_admin.name', 'super_admin');

            return $user instanceof \App\Models\Admin && is_string($role) && $user->hasRole($role) ? true : null;
        });

        Gate::define('viewPulse', function ($user): bool {
            $role = config('filament-shield.super_admin.name', 'super_admin');

            if ($user instanceof \App\Models\Admin && is_string($role) && $user->hasRole($role)) {
                return true;
            }

            return is_object($user) && is_string($role) && method_exists($user, 'hasRole') && $user->hasRole($role);
        });
    }

    private function configureVite(): void
    {
        if ($this->app->bound('csp-nonce')) {
            Vite::useCspNonce($this->app->make('csp-nonce'));
        }

        Vite::prefetch(concurrency: 3);
    }

    private function configureSocialite(): void
    {
        Event::listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            \SocialiteProviders\Apple\AppleExtendSocialite::class
        );
    }

    private function configureModelHooks(): void
    {
        $this->configureWorkoutHooks();
        $this->configureSetHooks();
        $this->configureMeasurementHooks();
    }

    private function configureWorkoutHooks(): void
    {
        Workout::saved(fn(Workout $workout) => \App\Jobs\SyncUserGoals::dispatch($workout->user));
        Workout::deleted(fn(Workout $workout) => \App\Jobs\SyncUserGoals::dispatch($workout->user));
        Workout::saved(fn(Workout $workout) => \App\Jobs\SyncUserAchievements::dispatch($workout->user));
        Workout::saved(fn(Workout $workout) => app(\App\Services\StreakService::class)->updateStreak($workout->user, $workout));
    }

    private function configureSetHooks(): void
    {
        Set::saved(fn(Set $set) => \App\Jobs\SyncUserGoals::dispatch($set->workoutLine->workout->user));
        Set::deleted(fn(Set $set) => \App\Jobs\SyncUserGoals::dispatch($set->workoutLine->workout->user));
        Set::saved(fn(Set $set) => \App\Jobs\SyncUserAchievements::dispatch($set->workoutLine->workout->user));

        Set::saved(function (Set $set): void {
            $user = $set->workoutLine->workout->user;
            $oldWeight = $set->getOriginal('weight');
            $oldReps = $set->getOriginal('reps');
            $oldVol = (is_numeric($oldWeight) ? (float) $oldWeight : 0.0) * (is_numeric($oldReps) ? (int) $oldReps : 0);
            $newVol = (float) ($set->weight ?? 0) * (int) ($set->reps ?? 0);
            $diff = $newVol - $oldVol;

            if ($diff !== 0.0) {
                $user->increment('total_volume', $diff);
            }
        });

        Set::deleted(function (Set $set): void {
            $user = $set->workoutLine->workout->user;
            $vol = (float) ($set->weight ?? 0) * (int) ($set->reps ?? 0);
            if ($vol !== 0.0) {
                $user->decrement('total_volume', $vol);
            }
        });

        Set::saved(fn(Set $set) => app(PersonalRecordService::class)->syncSetPRs($set));
    }

    private function configureMeasurementHooks(): void
    {
        BodyMeasurement::saved(fn(BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user));
        BodyMeasurement::deleted(fn(BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user));
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('api', function ($request): Limit {
            $configured = config('app.api_rate_limit', 60);
            $limit = is_numeric($configured) ? (int) $configured : 60;

            return Limit::perMinute($limit)->by($request->user()->id ?? $request->ip());
        });
    }
}
