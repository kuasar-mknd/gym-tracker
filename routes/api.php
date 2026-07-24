<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AchievementController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\BodyMeasurementController;
use App\Http\Controllers\Api\BodyPartMeasurementController;
use App\Http\Controllers\Api\DailyJournalController;
use App\Http\Controllers\Api\ExerciseController;
use App\Http\Controllers\Api\FastController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\HabitController;
use App\Http\Controllers\Api\HabitLogController;
use App\Http\Controllers\Api\IntervalTimerController;
use App\Http\Controllers\Api\MacroCalculationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\PersonalRecordController;
use App\Http\Controllers\Api\PlateController;
use App\Http\Controllers\Api\SetController;
use App\Http\Controllers\Api\SupplementController;
use App\Http\Controllers\Api\SupplementLogController;
use App\Http\Controllers\Api\UserAchievementController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarmupPreferenceController;
use App\Http\Controllers\Api\WaterLogController;
use App\Http\Controllers\Api\WilksScoreController;
use App\Http\Controllers\Api\WorkoutController;
use App\Http\Controllers\Api\WorkoutLineController;
use App\Http\Controllers\Api\WorkoutTemplateController;
use App\Http\Controllers\Api\WorkoutTemplateLineController;
use App\Http\Controllers\Api\WorkoutTemplateSetController;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', app()->isProduction() ? 'throttle:120,1' : 'throttle:1000,1'])->as('api.v1.')->group(function (): void {
    Route::get('/user', fn (Request $request): UserResource => new UserResource($request->user()));

    Route::apiResource('admins', AdminController::class);
    Route::apiResource('users', UserController::class);

    Route::apiResource('achievements', AchievementController::class);
    Route::apiResource('user-achievements', UserAchievementController::class);
    Route::apiResource('exercises', ExerciseController::class);
    Route::apiResource('plates', PlateController::class);
    Route::apiResource('workouts', WorkoutController::class);
    Route::apiResource('sets', SetController::class);
    Route::apiResource('personal-records', PersonalRecordController::class);
    Route::apiResource('body-measurements', BodyMeasurementController::class);
    Route::apiResource('body-part-measurements', BodyPartMeasurementController::class);
    Route::apiResource('goals', GoalController::class);
    Route::apiResource('workout-templates', WorkoutTemplateController::class);
    Route::apiResource('workout-template-lines', WorkoutTemplateLineController::class);
    Route::apiResource('workout-template-sets', WorkoutTemplateSetController::class);
    Route::apiResource('workout-lines', WorkoutLineController::class);
    Route::apiResource('daily-journals', DailyJournalController::class);
    Route::apiResource('fasts', FastController::class);
    Route::apiResource('notification-preferences', NotificationPreferenceController::class);
    Route::apiResource('warmup-preferences', WarmupPreferenceController::class);

    Route::apiResource('habits', HabitController::class);
    Route::apiResource('habit-logs', HabitLogController::class);
    Route::apiResource('supplements', SupplementController::class);
    Route::apiResource('supplement-logs', SupplementLogController::class);
    Route::apiResource('water-logs', WaterLogController::class);

    Route::apiResource('macro-calculations', MacroCalculationController::class);
    Route::apiResource('wilks-scores', WilksScoreController::class);
    Route::apiResource('interval-timers', IntervalTimerController::class);

    Route::get('/status', fn (): JsonResponse => response()->json(['status' => 'ok']));
});
