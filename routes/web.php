<?php

declare(strict_types=1);

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/dashboard', \App\Http\Controllers\DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/achievements', [\App\Http\Controllers\AchievementController::class, 'index'])->name('achievements.index');
    Route::get('/workouts', [\App\Http\Controllers\WorkoutsController::class, 'index'])->name('workouts.index');
    Route::get('/workouts/{workout}', [\App\Http\Controllers\WorkoutsController::class, 'show'])->name('workouts.show');
    Route::get('/calendar', [\App\Http\Controllers\CalendarController::class, 'index'])->name('calendar.index');
    Route::get('/stats', [\App\Http\Controllers\StatsController::class, 'index'])->name('stats.index');
    Route::get('/stats/exercise/{exercise}', [\App\Http\Controllers\StatsController::class, 'exercise'])->name('stats.exercise');
    Route::middleware('throttle:60,1')->group(function (): void {
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        Route::post('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
        Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-as-read');

        Route::post('/push-subscriptions', [\App\Http\Controllers\PushSubscriptionController::class, 'update'])->name('push-subscriptions.update');
        Route::post('/push-subscriptions/delete', [\App\Http\Controllers\PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');

        Route::resource('goals', \App\Http\Controllers\GoalController::class)->except(['index', 'show']);

        Route::post('/workouts', [\App\Http\Controllers\WorkoutsController::class, 'store'])->name('workouts.store');
        Route::patch('/workouts/{workout}', [\App\Http\Controllers\WorkoutsController::class, 'update'])->name('workouts.update');
        Route::delete('/workouts/{workout}', [\App\Http\Controllers\WorkoutsController::class, 'destroy'])->name('workouts.destroy');

        Route::resource('templates', \App\Http\Controllers\WorkoutTemplatesController::class)->except(['index', 'show']);
        Route::post('/templates/{template}/execute', [\App\Http\Controllers\WorkoutTemplatesController::class, 'execute'])->name('templates.execute');
        Route::post('/workouts/{workout}/save-as-template', [\App\Http\Controllers\WorkoutTemplatesController::class, 'saveFromWorkout'])->name('templates.save-from-workout');

        Route::post('/workouts/{workout}/lines', [\App\Http\Controllers\WorkoutLinesController::class, 'store'])->name('workout-lines.store');
        Route::delete('/workout-lines/{workoutLine}', [\App\Http\Controllers\WorkoutLinesController::class, 'destroy'])->name('workout-lines.destroy');

        Route::post('/workout-lines/{workoutLine}/sets', [\App\Http\Controllers\SetsController::class, 'store'])->name('sets.store');
        Route::patch('/sets/{set}', [\App\Http\Controllers\SetsController::class, 'update'])->name('sets.update');
        Route::delete('/sets/{set}', [\App\Http\Controllers\SetsController::class, 'destroy'])->name('sets.destroy');

        Route::post('/habits/{habit}/toggle', [\App\Http\Controllers\HabitController::class, 'toggle'])->name('habits.toggle');
        Route::resource('habits', \App\Http\Controllers\HabitController::class)->only(['store', 'update', 'destroy']);

        Route::resource('exercises', \App\Http\Controllers\ExerciseController::class)->only(['store', 'update', 'destroy']);
        Route::resource('body-measurements', \App\Http\Controllers\BodyMeasurementController::class)->only(['store', 'destroy']);

        Route::post('/body-metrics', [\App\Http\Controllers\BodyPartMeasurementController::class, 'store'])->name('body-parts.store');
        Route::delete('/body-metrics/{bodyPartMeasurement}', [\App\Http\Controllers\BodyPartMeasurementController::class, 'destroy'])->name('body-parts.destroy');

        Route::resource('plates', \App\Http\Controllers\PlateController::class)->only(['store', 'update', 'destroy']);
        Route::resource('daily-journals', \App\Http\Controllers\DailyJournalController::class)->only(['store', 'destroy']);

        Route::post('/supplements/{supplement}/consume', [\App\Http\Controllers\SupplementController::class, 'consume'])->name('supplements.consume');
        Route::resource('supplements', \App\Http\Controllers\SupplementController::class)->only(['store', 'update', 'destroy']);
    });

    Route::resource('supplements', \App\Http\Controllers\SupplementController::class)->only(['index']);
    Route::resource('habits', \App\Http\Controllers\HabitController::class)->only(['index']);
    Route::resource('goals', \App\Http\Controllers\GoalController::class)->only(['index', 'show']);
    Route::resource('templates', \App\Http\Controllers\WorkoutTemplatesController::class)->only(['index', 'show']);
    Route::resource('exercises', \App\Http\Controllers\ExerciseController::class)->only(['index', 'show']);
    Route::resource('body-measurements', \App\Http\Controllers\BodyMeasurementController::class)->only(['index']);

    Route::get('/body-metrics', [\App\Http\Controllers\BodyPartMeasurementController::class, 'index'])->name('body-parts.index');
    Route::get('/body-metrics/{part}', [\App\Http\Controllers\BodyPartMeasurementController::class, 'show'])->name('body-parts.show');

    Route::resource('plates', \App\Http\Controllers\PlateController::class)->only(['index']);
    Route::resource('daily-journals', \App\Http\Controllers\DailyJournalController::class)->only(['index']);

    Route::get('/tools', [\App\Http\Controllers\ToolsController::class, 'index'])->name('tools.index');
    Route::get('/tools/1rm', [\App\Http\Controllers\ToolsController::class, 'oneRepMax'])->name('tools.1rm');
    Route::get('/tools/wilks', [\App\Http\Controllers\WilksScoreController::class, 'index'])->name('tools.wilks');
    Route::post('/tools/wilks', [\App\Http\Controllers\WilksScoreController::class, 'store'])->name('tools.wilks.store');
    Route::delete('/tools/wilks/{wilksScore}', [\App\Http\Controllers\WilksScoreController::class, 'destroy'])->name('tools.wilks.destroy');

    Route::get('/tools/macro-calculator', [\App\Http\Controllers\MacroCalculatorController::class, 'index'])->name('tools.macro-calculator');
    Route::post('/tools/macro-calculator', [\App\Http\Controllers\MacroCalculatorController::class, 'store'])->name('tools.macro-calculator.store');
    Route::delete('/tools/macro-calculator/{macroCalculation}', [\App\Http\Controllers\MacroCalculatorController::class, 'destroy'])->name('tools.macro-calculator.destroy');

    Route::get('/tools/warmup', [\App\Http\Controllers\WarmupController::class, 'index'])->name('tools.warmup');
    Route::post('/tools/warmup', [\App\Http\Controllers\WarmupController::class, 'update'])->name('tools.warmup.update');

    Route::get('/tools/water', [\App\Http\Controllers\WaterController::class, 'index'])->name('tools.water.index');
    Route::post('/tools/water', [\App\Http\Controllers\WaterController::class, 'store'])->name('tools.water.store');
    Route::delete('/tools/water/{waterLog}', [\App\Http\Controllers\WaterController::class, 'destroy'])->name('tools.water.destroy');
});

// Social Login
Route::get('/auth/{provider}/redirect', [\App\Http\Controllers\Auth\SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
Route::get('/auth/{provider}/callback', [\App\Http\Controllers\Auth\SocialAuthController::class, 'callback'])
    ->name('social.callback');

require __DIR__.'/auth.php';
