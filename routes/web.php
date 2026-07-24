<?php

declare(strict_types=1);

use App\Http\Controllers\AchievementController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\BodyMeasurementController;
use App\Http\Controllers\BodyPartMeasurementController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DailyJournalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExerciseController;
use App\Http\Controllers\FastingController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\HabitController;
use App\Http\Controllers\IntervalTimerController;
use App\Http\Controllers\MacroCalculatorController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlateController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SetController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\SupplementController;
use App\Http\Controllers\ToolsController;
use App\Http\Controllers\WarmupController;
use App\Http\Controllers\WaterController;
use App\Http\Controllers\WilksScoreController;
use App\Http\Controllers\WorkoutController;
use App\Http\Controllers\WorkoutLineController;
use App\Http\Controllers\WorkoutTemplateController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/achievements', [AchievementController::class, 'index'])->name('achievements.index');
    Route::get('/workouts', [WorkoutController::class, 'index'])->name('workouts.index');
    Route::get('/workouts/{workout}', [WorkoutController::class, 'show'])->name('workouts.show');
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');
    Route::get('/stats/exercise/{exercise}', [StatsController::class, 'exercise'])->name('stats.exercise');

    Route::resource('supplements', SupplementController::class)->only(['index']);
    Route::resource('habits', HabitController::class)->only(['index']);
    Route::resource('goals', GoalController::class)->only(['index', 'show']);
    Route::resource('templates', WorkoutTemplateController::class)->only(['index', 'show', 'create', 'edit']);
    Route::resource('exercises', ExerciseController::class)->only(['index', 'show']);
    Route::resource('body-measurements', BodyMeasurementController::class)->only(['index']);

    Route::get('/body-metrics', [BodyPartMeasurementController::class, 'index'])->name('body-parts.index');
    Route::get('/body-metrics/{part}', [BodyPartMeasurementController::class, 'show'])->name('body-parts.show');

    Route::resource('plates', PlateController::class)->only(['index']);
    Route::resource('daily-journals', DailyJournalController::class)->only(['index']);

    Route::get('/tools', [ToolsController::class, 'index'])->name('tools.index');
    Route::get('/tools/1rm', [ToolsController::class, 'oneRepMax'])->name('tools.1rm');
    Route::get('/tools/wilks', [WilksScoreController::class, 'index'])->name('tools.wilks');
    Route::get('/tools/macro-calculator', [MacroCalculatorController::class, 'index'])->name('tools.macro-calculator');
    Route::get('/tools/warmup', [WarmupController::class, 'index'])->name('tools.warmup');
    Route::get('/tools/water', [WaterController::class, 'index'])->name('tools.water.index');
    Route::get('/tools/interval-timer', [IntervalTimerController::class, 'index'])->name('tools.interval-timer.index');
    Route::get('/tools/fasting', [FastingController::class, 'index'])->name('tools.fasting.index');

    Route::middleware(app()->isProduction() ? 'throttle:60,1' : 'throttle:1000,1')->group(function (): void {
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-as-read');

        Route::post('/push-subscriptions', [PushSubscriptionController::class, 'update'])->name('push-subscriptions.update');
        Route::post('/push-subscriptions/delete', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');

        Route::resource('goals', GoalController::class)->except(['index', 'show']);

        Route::post('/workouts', [WorkoutController::class, 'store'])->name('workouts.store');
        Route::patch('/workouts/{workout}', [WorkoutController::class, 'update'])->name('workouts.update');
        Route::delete('/workouts/{workout}', [WorkoutController::class, 'destroy'])->name('workouts.destroy');

        Route::resource('templates', WorkoutTemplateController::class)->except(['index', 'show', 'create', 'edit']);
        Route::post('/templates/{template}/execute', [WorkoutTemplateController::class, 'execute'])->name('templates.execute');
        Route::post('/workouts/{workout}/save-as-template', [WorkoutTemplateController::class, 'saveFromWorkout'])->name('templates.save-from-workout');

        Route::post('/workouts/{workout}/lines', [WorkoutLineController::class, 'store'])->name('workout-lines.store');
        Route::delete('/workout-lines/{workoutLine}', [WorkoutLineController::class, 'destroy'])->name('workout-lines.destroy');

        Route::post('/workout-lines/{workoutLine}/sets', [SetController::class, 'store'])->name('sets.store');
        Route::patch('/sets/{set}', [SetController::class, 'update'])->name('sets.update');
        Route::delete('/sets/{set}', [SetController::class, 'destroy'])->name('sets.destroy');

        // Habit routes
        Route::post('/habits/{habit}/toggle', [HabitController::class, 'toggle'])->name('habits.toggle');
        Route::resource('habits', HabitController::class)->only(['store', 'update', 'destroy']);

        // Exercise routes
        Route::get('/exercises/{exercise}', [ExerciseController::class, 'show'])->name('exercises.show');
        Route::resource('exercises', ExerciseController::class)->only(['store', 'update', 'destroy']);

        // Body Measurement routes
        Route::resource('body-measurements', BodyMeasurementController::class)->only(['store', 'destroy']);
        Route::post('/body-metrics', [BodyPartMeasurementController::class, 'store'])->name('body-parts.store');
        Route::delete('/body-metrics/{bodyPartMeasurement}', [BodyPartMeasurementController::class, 'destroy'])->name('body-parts.destroy');

        Route::resource('plates', PlateController::class)->only(['store', 'update', 'destroy']);
        Route::resource('daily-journals', DailyJournalController::class)->only(['store', 'destroy']);

        // Supplement routes
        Route::post('/supplements/{supplement}/consume', [SupplementController::class, 'consume'])->name('supplements.consume');
        Route::resource('supplements', SupplementController::class)->only(['store', 'update', 'destroy']);

        // Tools routes
        Route::post('/tools/wilks', [WilksScoreController::class, 'store'])->name('tools.wilks.store');
        Route::delete('/tools/wilks/{wilksScore}', [WilksScoreController::class, 'destroy'])->name('tools.wilks.destroy');

        Route::post('/tools/macro-calculator', [MacroCalculatorController::class, 'store'])->name('tools.macro-calculator.store');
        Route::delete('/tools/macro-calculator/{macroCalculation}', [MacroCalculatorController::class, 'destroy'])->name('tools.macro-calculator.destroy');

        Route::post('/tools/warmup', [WarmupController::class, 'update'])->name('tools.warmup.update');

        Route::post('/tools/water', [WaterController::class, 'store'])->name('tools.water.store');
        Route::delete('/tools/water/{waterLog}', [WaterController::class, 'destroy'])->name('tools.water.destroy');

        Route::post('/tools/interval-timer', [IntervalTimerController::class, 'store'])->name('tools.interval-timer.store');
        Route::patch('/tools/interval-timer/{intervalTimer}', [IntervalTimerController::class, 'update'])->name('tools.interval-timer.update');
        Route::delete('/tools/interval-timer/{intervalTimer}', [IntervalTimerController::class, 'destroy'])->name('tools.interval-timer.destroy');

        Route::post('/tools/fasting', [FastingController::class, 'store'])->name('tools.fasting.store');
        Route::patch('/tools/fasting/{fast}', [FastingController::class, 'update'])->name('tools.fasting.update');
        Route::delete('/tools/fasting/{fast}', [FastingController::class, 'destroy'])->name('tools.fasting.destroy');
    });
});

// Social Login
Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->middleware('guest')
    ->name('social.redirect');
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->middleware('guest')
    ->name('social.callback');

require __DIR__.'/auth.php';
