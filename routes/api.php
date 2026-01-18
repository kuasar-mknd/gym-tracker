<?php

use App\Http\Controllers\Api\BodyMeasurementController;
use App\Http\Controllers\Api\ExerciseController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\SetController;
use App\Http\Controllers\Api\WorkoutController;
use App\Http\Controllers\Api\WorkoutTemplateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1'])->as('api.v1.')->group(function () {
    Route::get('/user', function (Request $request) {
        return new \App\Http\Resources\UserResource($request->user());
    });

    Route::apiResource('exercises', ExerciseController::class);
    Route::apiResource('workouts', WorkoutController::class);
    Route::apiResource('sets', SetController::class);
    Route::apiResource('personal-records', \App\Http\Controllers\Api\PersonalRecordController::class);
    Route::apiResource('body-measurements', BodyMeasurementController::class);
    Route::apiResource('goals', GoalController::class);
    Route::apiResource('workout-templates', WorkoutTemplateController::class);
    Route::apiResource('daily-journals', \App\Http\Controllers\Api\DailyJournalController::class);
    Route::apiResource('plates', \App\Http\Controllers\Api\PlateController::class);

    Route::get('/status', function () {
        return response()->json(['status' => 'ok']);
    });
});
