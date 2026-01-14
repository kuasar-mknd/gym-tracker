<?php

use App\Http\Controllers\Api\ExerciseController;
use App\Http\Controllers\Api\WorkoutController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::apiResource('exercises', ExerciseController::class);
    Route::apiResource('workouts', WorkoutController::class);
});
