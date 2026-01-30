<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\BodyMeasurement;
use App\Models\Set;
use App\Models\Workout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enforce strict model behavior in non-production environments
        // This prevents N+1 queries (preventLazyLoading) and Mass Assignment vulnerabilities (preventSilentlyDiscardingAttributes).
        // We do NOT enable preventAccessingMissingAttributes to avoid breaking existing tests/logic that rely on lenient attribute access.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->configureGates();
        $this->configureVite();
        $this->configureSocialite();
        $this->configureModelHooks();
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
        Workout::saved(fn (Workout $workout) => \App\Jobs\SyncUserGoals::dispatch($workout->user));
        Workout::deleted(fn (Workout $workout) => \App\Jobs\SyncUserGoals::dispatch($workout->user));

        Set::saved(fn (Set $set) => \App\Jobs\SyncUserGoals::dispatch($set->workoutLine->workout->user));
        Set::deleted(fn (Set $set) => \App\Jobs\SyncUserGoals::dispatch($set->workoutLine->workout->user));

        BodyMeasurement::saved(fn (BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user));
        BodyMeasurement::deleted(fn (BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user));

        Workout::saved(fn (Workout $workout) => \App\Jobs\SyncUserAchievements::dispatch($workout->user));
        Set::saved(fn (Set $set) => \App\Jobs\SyncUserAchievements::dispatch($set->workoutLine->workout->user));

        Workout::saved(fn (Workout $workout) => app(\App\Services\StreakService::class)->updateStreak($workout->user, $workout));
    }
}
