<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BodyMeasurementController;
use App\Http\Controllers\Api\BodyPartMeasurementController;
use App\Http\Controllers\Api\ExerciseController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\SetController;
use App\Http\Controllers\Api\WorkoutController;
use App\Http\Controllers\Api\WorkoutTemplateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1'])->as('api.v1.')->group(function (): void {
    Route::get('/user', fn (Request $request): \App\Http\Resources\UserResource => new \App\Http\Resources\UserResource($request->user()));

    Route::apiResource('exercises', ExerciseController::class);
    Route::apiResource('plates', \App\Http\Controllers\Api\PlateController::class);
    Route::apiResource('workouts', WorkoutController::class);
    Route::apiResource('sets', SetController::class);
    Route::apiResource('personal-records', \App\Http\Controllers\Api\PersonalRecordController::class);
    Route::apiResource('body-measurements', BodyMeasurementController::class);
    Route::apiResource('body-part-measurements', BodyPartMeasurementController::class);
    Route::apiResource('goals', GoalController::class);
    Route::apiResource('workout-templates', WorkoutTemplateController::class);
    Route::apiResource('daily-journals', \App\Http\Controllers\Api\DailyJournalController::class);
    Route::apiResource('notification-preferences', \App\Http\Controllers\Api\NotificationPreferenceController::class);
    Route::apiResource('warmup-preferences', \App\Http\Controllers\Api\WarmupPreferenceController::class);
    Route::apiResource('plates', \App\Http\Controllers\Api\PlateController::class);
    Route::apiResource('habits', \App\Http\Controllers\Api\HabitController::class);
    Route::apiResource('habit-logs', \App\Http\Controllers\Api\HabitLogController::class);
    Route::apiResource('supplements', \App\Http\Controllers\Api\SupplementController::class);
    Route::apiResource('supplement-logs', \App\Http\Controllers\Api\SupplementLogController::class);
    Route::apiResource('water-logs', \App\Http\Controllers\Api\WaterLogController::class);
    Route::apiResource('fasts', \App\Http\Controllers\Api\FastController::class);
    Route::apiResource('macro-calculations', \App\Http\Controllers\Api\MacroCalculationController::class);
    Route::apiResource('interval-timers', \App\Http\Controllers\Api\IntervalTimerController::class);

    Route::get('/status', fn () => response()->json(['status' => 'ok']));
});
