<?php

use App\Http\Controllers\Api\ExerciseController;
use App\Http\Controllers\Api\WorkoutController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->as('api.v1.')->group(function () {
    Route::get('/user', function (Request $request) {
        return new \App\Http\Resources\UserResource($request->user());
    });

    Route::apiResource('exercises', ExerciseController::class);
    Route::apiResource('workouts', WorkoutController::class);
    Route::apiResource('personal-records', \App\Http\Controllers\Api\PersonalRecordController::class);

    Route::get('/status', function () {
        return response()->json(['status' => 'ok']);
    });
});
