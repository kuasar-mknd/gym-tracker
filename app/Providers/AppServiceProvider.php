<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\BodyMeasurement;
use App\Models\Set;
use App\Models\Workout;
use App\Services\PersonalRecordService;
use App\Services\StreakService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        // Enforce strict model behavior in non-production environments
        // This prevents N+1 queries (preventLazyLoading) and Mass Assignment vulnerabilities (preventSilentlyDiscardingAttributes).
        // We do NOT enable preventAccessingMissingAttributes to avoid breaking existing tests/logic that rely on lenient attribute access.
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
        $this->configureLivewire();
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

    private function configureLivewire(): void
    {
        if ($this->app->bound('csp-nonce')) {
            config(['livewire.nonce' => $this->app->make('csp-nonce')]);
        }
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
        $syncGoals = function (?\App\Models\User $user, bool $debounce = false): void {
            if (! $user) {
                return;
            }
            if ($debounce) {
                $key = 'dispatched:sync-goals:'.$user->id;
                if ($this->app->bound($key)) {
                    return;
                }
                $this->app->instance($key, true);
            }
            \App\Jobs\SyncUserGoals::dispatch($user);
        };

        $syncAchievements = function (?\App\Models\User $user, bool $debounce = false): void {
            if (! $user) {
                return;
            }
            if ($debounce) {
                $key = 'dispatched:sync-achievements:'.$user->id;
                if ($this->app->bound($key)) {
                    return;
                }
                $this->app->instance($key, true);
            }
            \App\Jobs\SyncUserAchievements::dispatch($user);
        };

        // Workouts always trigger (covers creation and completion)
        Workout::saved(fn (Workout $workout) => $syncGoals($workout->user));
        Workout::deleted(fn (Workout $workout) => $syncGoals($workout->user));
        Workout::saved(fn (Workout $workout) => $syncAchievements($workout->user));
        Workout::deleted(fn (Workout $workout) => $syncAchievements($workout->user));
        Workout::saved(fn (Workout $workout) => app(StreakService::class)->updateStreak($workout->user, $workout));

        // Sets only trigger if the workout is finished, and they are debounced per request
        Set::saved(function (Set $set) use ($syncGoals): void {
            $workout = $set->workoutLine->workout;
            if ($workout->ended_at !== null) {
                $syncGoals($workout->user, true);
            }
            $this->updateUserVolume($set);
            app(PersonalRecordService::class)->syncSetPRs($set);
        });

        Set::deleted(function (Set $set) use ($syncGoals): void {
            $workout = $set->workoutLine->workout;
            if ($workout->ended_at !== null) {
                $syncGoals($workout->user, true);
            }
            $this->decrementUserVolume($set);
        });

        Set::saved(function (Set $set) use ($syncAchievements): void {
            $workout = $set->workoutLine->workout;
            if ($workout->ended_at !== null) {
                $syncAchievements($workout->user, true);
            }
        });

        // Body Measurements always trigger
        BodyMeasurement::saved(fn (BodyMeasurement $bm) => $syncGoals($bm->user));
        BodyMeasurement::deleted(fn (BodyMeasurement $bm) => $syncGoals($bm->user));
    }

    private function updateUserVolume(Set $set): void
    {
        $workout = $set->workoutLine->workout;
        $u = $workout->user;
        $ow = $set->getOriginal('weight');
        $or = $set->getOriginal('reps');
        $ov = (is_numeric($ow) ? (float) $ow : 0.0) * (is_numeric($or) ? (int) $or : 0);
        $nv = (float) ($set->weight ?? 0) * (int) ($set->reps ?? 0);
        $d = $nv - $ov;

        if ($d !== 0.0) {
            $u->increment('total_volume', $d);
            $workout->increment('volume', $d);
        }
    }

    private function decrementUserVolume(Set $set): void
    {
        $workout = $set->workoutLine->workout;
        $u = $workout->user;
        $v = (float) ($set->weight ?? 0) * (int) ($set->reps ?? 0);
        if ($v !== 0.0) {
            $u->decrement('total_volume', $v);
            $workout->decrement('volume', $v);
        }
    }
}
