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
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/workouts', [\App\Http\Controllers\WorkoutsController::class, 'index'])->name('workouts.index');
    Route::post('/workouts', [\App\Http\Controllers\WorkoutsController::class, 'store'])->name('workouts.store');
    Route::get('/workouts/{workout}', [\App\Http\Controllers\WorkoutsController::class, 'show'])->name('workouts.show');

    Route::post('/workouts/{workout}/lines', [\App\Http\Controllers\WorkoutLinesController::class, 'store'])->name('workout-lines.store');
    Route::delete('/workout-lines/{line}', [\App\Http\Controllers\WorkoutLinesController::class, 'destroy'])->name('workout-lines.destroy');

    Route::post('/workout-lines/{workoutLine}/sets', [\App\Http\Controllers\SetsController::class, 'store'])->name('sets.store');
    Route::patch('/sets/{set}', [\App\Http\Controllers\SetsController::class, 'update'])->name('sets.update');
    Route::delete('/sets/{set}', [\App\Http\Controllers\SetsController::class, 'destroy'])->name('sets.destroy');

    Route::resource('body-measurements', \App\Http\Controllers\BodyMeasurementController::class)
        ->only(['index', 'store', 'destroy']);
});

require __DIR__.'/auth.php';
