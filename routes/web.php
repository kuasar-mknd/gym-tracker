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

Route::get('/dashboard', function () {
    $user = auth()->user();

    // Optimize: fetch counts directly from DB instead of loading all workouts
    $workoutsCount = $user->workouts()->count();

    $startOfWeek = now()->startOfWeek();
    $thisWeekCount = $user->workouts()
        ->where('started_at', '>=', $startOfWeek)
        ->count();

    $latestMeasurement = $user->bodyMeasurements()->latest('measured_at')->first();

    // Optimize: only fetch the 5 most recent workouts with eager loading
    $recentWorkouts = $user->workouts()
        ->with('workoutLines.exercise', 'workoutLines.sets')
        ->latest()
        ->limit(5)
        ->get();

    $recentPRs = $user->personalRecords()
        ->with('exercise')
        ->latest('achieved_at')
        ->take(5)
        ->get();

    return Inertia::render('Dashboard', [
        'workoutsCount' => $workoutsCount,
        'thisWeekCount' => $thisWeekCount,
        'latestWeight' => $latestMeasurement?->weight,
        'recentWorkouts' => $recentWorkouts,
        'recentPRs' => $recentPRs,
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

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
});

// Social Login
Route::get('/auth/{provider}/redirect', [\App\Http\Controllers\Auth\SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
Route::get('/auth/{provider}/callback', [\App\Http\Controllers\Auth\SocialAuthController::class, 'callback'])
    ->name('social.callback');

require __DIR__.'/auth.php';
