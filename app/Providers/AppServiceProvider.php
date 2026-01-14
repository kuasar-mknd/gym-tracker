<?php

namespace App\Providers;

use App\Models\BodyMeasurement;
use App\Models\Set;
use App\Models\Workout;
use App\Services\AchievementService;
use App\Services\GoalService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Event::listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            \SocialiteProviders\Apple\AppleExtendSocialite::class
        );

        // Goal Tracking Hooks
        Workout::saved(fn (Workout $workout) => app(GoalService::class)->syncGoals($workout->user));
        Workout::deleted(fn (Workout $workout) => app(GoalService::class)->syncGoals($workout->user));

        Set::saved(fn (Set $set) => app(GoalService::class)->syncGoals($set->workoutLine->workout->user));
        Set::deleted(fn (Set $set) => app(GoalService::class)->syncGoals($set->workoutLine->workout->user));

        BodyMeasurement::saved(fn (BodyMeasurement $bm) => app(GoalService::class)->syncGoals($bm->user));
        BodyMeasurement::deleted(fn (BodyMeasurement $bm) => app(GoalService::class)->syncGoals($bm->user));

        // Achievement Tracking Hooks
        Workout::saved(fn (Workout $workout) => app(AchievementService::class)->syncAchievements($workout->user));
        Set::saved(fn (Set $set) => app(AchievementService::class)->syncAchievements($set->workoutLine->workout->user));

        // Streak Tracking Hooks
        Workout::saved(fn (Workout $workout) => app(\App\Services\StreakService::class)->updateStreak($workout->user));
    }
}
