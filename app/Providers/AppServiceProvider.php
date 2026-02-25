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
        $goals = fn (Workout $w) => \App\Jobs\SyncUserGoals::dispatch($w->user);
        Workout::saved($goals);
        Workout::deleted($goals);
        Workout::saved(fn (Workout $w) => \App\Jobs\SyncUserAchievements::dispatch($w->user));
        Workout::saved(fn (Workout $w) => app(StreakService::class)->updateStreak($w->user, $w));
    }

    private function configureSetHooks(): void
    {
        $goals = fn (Set $s) => \App\Jobs\SyncUserGoals::dispatch($s->workoutLine->workout->user);
        Set::saved($goals);
        Set::deleted($goals);
        Set::saved(fn (Set $s) => \App\Jobs\SyncUserAchievements::dispatch($s->workoutLine->workout->user));

        Set::saved(function (Set $set): void {
            $this->updateUserVolume($set);
        });

        Set::deleted(function (Set $set): void {
            $this->decrementUserVolume($set);
        });

        Set::saved(fn (Set $s) => app(PersonalRecordService::class)->syncSetPRs($s));
    }

    private function updateUserVolume(Set $set): void
    {
        $u = $set->workoutLine->workout->user;
        $ow = $set->getOriginal('weight');
        $or = $set->getOriginal('reps');
        $ov = (is_numeric($ow) ? (float) $ow : 0.0) * (is_numeric($or) ? (int) $or : 0);
        $nv = (float) ($set->weight ?? 0) * (int) ($set->reps ?? 0);
        $d = $nv - $ov;

        if ($d !== 0.0) {
            $u->increment('total_volume', $d);
        }
    }

    private function decrementUserVolume(Set $set): void
    {
        $u = $set->workoutLine->workout->user;
        $v = (float) ($set->weight ?? 0) * (int) ($set->reps ?? 0);
        if ($v !== 0.0) {
            $u->decrement('total_volume', $v);
        }
    }

    private function configureMeasurementHooks(): void
    {
        $goals = fn (BodyMeasurement $bm) => \App\Jobs\SyncUserGoals::dispatch($bm->user);
        BodyMeasurement::saved($goals);
        BodyMeasurement::deleted($goals);
    }
}
