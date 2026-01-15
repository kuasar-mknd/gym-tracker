<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', \App\Http\Controllers\DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-as-read');

    Route::post('/push-subscriptions', [\App\Http\Controllers\PushSubscriptionController::class, 'update'])->name('push-subscriptions.update');
    Route::post('/push-subscriptions/delete', [\App\Http\Controllers\PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');

    Route::resource('goals', \App\Http\Controllers\GoalController::class);
    Route::get('/achievements', [\App\Http\Controllers\AchievementController::class, 'index'])->name('achievements.index');

    Route::get('/workouts', [\App\Http\Controllers\WorkoutsController::class, 'index'])->name('workouts.index');
    Route::post('/workouts', [\App\Http\Controllers\WorkoutsController::class, 'store'])->name('workouts.store');
    Route::get('/workouts/{workout}', [\App\Http\Controllers\WorkoutsController::class, 'show'])->name('workouts.show');

    Route::resource('templates', \App\Http\Controllers\WorkoutTemplatesController::class);
    Route::post('/templates/{template}/execute', [\App\Http\Controllers\WorkoutTemplatesController::class, 'execute'])->name('templates.execute');
    Route::post('/workouts/{workout}/save-as-template', [\App\Http\Controllers\WorkoutTemplatesController::class, 'saveFromWorkout'])->name('templates.save-from-workout');

    Route::get('/stats', [\App\Http\Controllers\StatsController::class, 'index'])->name('stats.index');
    Route::get('/stats/exercise/{exercise}', [\App\Http\Controllers\StatsController::class, 'exercise'])->name('stats.exercise');

    Route::post('/workouts/{workout}/lines', [\App\Http\Controllers\WorkoutLinesController::class, 'store'])->name('workout-lines.store');
    Route::delete('/workout-lines/{workoutLine}', [\App\Http\Controllers\WorkoutLinesController::class, 'destroy'])->name('workout-lines.destroy');

    Route::post('/workout-lines/{workoutLine}/sets', [\App\Http\Controllers\SetsController::class, 'store'])->name('sets.store');
    Route::patch('/sets/{set}', [\App\Http\Controllers\SetsController::class, 'update'])->name('sets.update');
    Route::delete('/sets/{set}', [\App\Http\Controllers\SetsController::class, 'destroy'])->name('sets.destroy');

    Route::resource('exercises', \App\Http\Controllers\ExerciseController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    Route::resource('body-measurements', \App\Http\Controllers\BodyMeasurementController::class)
        ->only(['index', 'store', 'destroy']);

    Route::resource('plates', \App\Http\Controllers\PlateController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    Route::resource('daily-journals', \App\Http\Controllers\DailyJournalController::class)
        ->only(['index', 'store', 'destroy']);
});

// Social Login
Route::get('/auth/{provider}/redirect', [\App\Http\Controllers\Auth\SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
Route::get('/auth/{provider}/callback', [\App\Http\Controllers\Auth\SocialAuthController::class, 'callback'])
    ->name('social.callback');

require __DIR__.'/auth.php';
