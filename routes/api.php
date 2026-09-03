<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SetController;
use App\Http\Controllers\Api\WorkoutController;
use App\Http\Controllers\Api\WorkoutLineController;
use Illuminate\Support\Facades\Route;

/*
 * Les sept écritures que la page de séance fait en direct, et rien d'autre :
 * l'API REST complète (28 ressources, 144 routes) n'avait aucun client, et
 * chaque route ouverte était une surface à défendre (#1673).
 */
Route::prefix('v1')->middleware(['auth:sanctum', app()->isProduction() ? 'throttle:120,1' : 'throttle:1000,1'])->as('api.v1.')->group(function (): void {
    Route::post('sets', [SetController::class, 'store'])->name('sets.store');
    Route::match(['put', 'patch'], 'sets/{set}', [SetController::class, 'update'])->name('sets.update');
    Route::delete('sets/{set}', [SetController::class, 'destroy'])->name('sets.destroy');

    Route::post('workout-lines', [WorkoutLineController::class, 'store'])->name('workout-lines.store');
    // `{workout_line}` et non `{workoutLine}` : c'est le nom que la page passe à
    // Ziggy, hérité de l'ancien apiResource ; la liaison implicite accepte les deux.
    Route::delete('workout-lines/{workout_line}', [WorkoutLineController::class, 'destroy'])->name('workout-lines.destroy');
    Route::patch('workout-lines/{workoutLine}/set-order', [WorkoutLineController::class, 'reorderSets'])->name('workout-lines.set-order');

    Route::patch('workouts/{workout}/line-order', [WorkoutController::class, 'reorderLines'])->name('workouts.line-order');
});
