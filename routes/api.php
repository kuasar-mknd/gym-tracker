<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SetController;
use App\Http\Controllers\Api\WorkoutController;
use App\Http\Controllers\Api\WorkoutLineController;
use Illuminate\Support\Facades\Route;

/*
 * Les neuf écritures que la page de séance fait en direct ou par la file hors
 * ligne, et rien d'autre : l'API REST complète (28 ressources, 144 routes)
 * n'avait aucun client, et chaque route ouverte était une surface à défendre
 * (#1673). Démarrer et terminer une séance s'y ajoutent pour la file (#2049) :
 * la fin se nomme dans le corps, une séance encore en file n'ayant pas
 * d'identifiant à mettre dans l'adresse.
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

    Route::post('workouts', [WorkoutController::class, 'store'])->name('workouts.store');
    Route::patch('workouts/finish', [WorkoutController::class, 'finish'])->name('workouts.finish');
    Route::patch('workouts/{workout}/line-order', [WorkoutController::class, 'reorderLines'])->name('workouts.line-order');
});
