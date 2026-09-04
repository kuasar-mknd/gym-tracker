<?php

declare(strict_types=1);

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/dashboard', \App\Http\Controllers\DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/achievements', [\App\Http\Controllers\AchievementController::class, 'index'])->name('achievements.index');
    Route::get('/workouts', [\App\Http\Controllers\WorkoutController::class, 'index'])->name('workouts.index');
    Route::get('/workouts/{workout}', [\App\Http\Controllers\WorkoutController::class, 'show'])->name('workouts.show');
    Route::get('/calendar', [\App\Http\Controllers\CalendarController::class, 'index'])->name('calendar.index');
    Route::get('/stats', [\App\Http\Controllers\StatsController::class, 'index'])->name('stats.index');
    Route::get('/stats/exercise/{exercise}', [\App\Http\Controllers\StatsController::class, 'exercise'])->name('stats.exercise');

    Route::resource('supplements', \App\Http\Controllers\SupplementController::class)->only(['index']);
    Route::resource('habits', \App\Http\Controllers\HabitController::class)->only(['index']);
    // 'show' is excluded for the same reason as 'create' below: there is no
    // Goals/Show page and GoalController has no show() method, so the generated
    // route answered 500. Goals are consulted from the index.
    Route::resource('goals', \App\Http\Controllers\GoalController::class)->only(['index']);
    Route::resource('templates', \App\Http\Controllers\WorkoutTemplateController::class)->only(['index', 'show', 'create', 'edit']);
    Route::resource('exercises', \App\Http\Controllers\ExerciseController::class)->only(['index', 'show']);
    Route::resource('body-measurements', \App\Http\Controllers\BodyMeasurementController::class)->only(['index']);

    Route::get('/body-metrics', [\App\Http\Controllers\BodyPartMeasurementController::class, 'index'])->name('body-parts.index');
    Route::get('/body-metrics/{part}', [\App\Http\Controllers\BodyPartMeasurementController::class, 'show'])->name('body-parts.show');

    Route::resource('plates', \App\Http\Controllers\PlateController::class)->only(['index']);
    Route::resource('daily-journals', \App\Http\Controllers\DailyJournalController::class)->only(['index']);

    Route::get('/tools', [\App\Http\Controllers\ToolsController::class, 'index'])->name('tools.index');
    Route::get('/tools/1rm', [\App\Http\Controllers\ToolsController::class, 'oneRepMax'])->name('tools.1rm');
    Route::get('/tools/wilks', [\App\Http\Controllers\WilksScoreController::class, 'index'])->name('tools.wilks');
    Route::get('/tools/macro-calculator', [\App\Http\Controllers\MacroCalculatorController::class, 'index'])->name('tools.macro-calculator');
    Route::get('/tools/warmup', [\App\Http\Controllers\WarmupController::class, 'index'])->name('tools.warmup');
    Route::get('/tools/water', [\App\Http\Controllers\WaterController::class, 'index'])->name('tools.water.index');
    Route::get('/tools/interval-timer', [\App\Http\Controllers\IntervalTimerController::class, 'index'])->name('tools.interval-timer.index');
    Route::get('/tools/fasting', [\App\Http\Controllers\FastingController::class, 'index'])->name('tools.fasting.index');

    Route::middleware(app()->isProduction() ? 'throttle:60,1' : 'throttle:1000,1')->group(function (): void {
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences.update');
        Route::patch('/profile/rest-timer', [ProfileController::class, 'updateRestTimerPreference'])->name('profile.rest-timer.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        Route::post('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
        Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-as-read');

        Route::post('/push-subscriptions', [\App\Http\Controllers\PushSubscriptionController::class, 'update'])->name('push-subscriptions.update');
        Route::post('/push-subscriptions/delete', [\App\Http\Controllers\PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');

        // 'create' is excluded on purpose: goals are created from a form that
        // expands on the index, so there is no create page and the generated
        // route pointed at a controller method that does not exist.
        Route::resource('goals', \App\Http\Controllers\GoalController::class)->except(['index', 'show', 'create']);

        Route::post('/workouts', [\App\Http\Controllers\WorkoutController::class, 'store'])->name('workouts.store');
        Route::patch('/workouts/{workout}', [\App\Http\Controllers\WorkoutController::class, 'update'])->name('workouts.update');
        Route::delete('/workouts/{workout}', [\App\Http\Controllers\WorkoutController::class, 'destroy'])->name('workouts.destroy');

        Route::resource('templates', \App\Http\Controllers\WorkoutTemplateController::class)->except(['index', 'show', 'create', 'edit']);
        Route::post('/templates/{template}/execute', [\App\Http\Controllers\WorkoutTemplateController::class, 'execute'])->name('templates.execute');
        Route::post('/workouts/{workout}/save-as-template', [\App\Http\Controllers\WorkoutTemplateController::class, 'saveFromWorkout'])->name('templates.save-from-workout');

        // Habit routes
        Route::post('/habits/{habit}/toggle', [\App\Http\Controllers\HabitController::class, 'toggle'])->name('habits.toggle');
        Route::resource('habits', \App\Http\Controllers\HabitController::class)->only(['store', 'update', 'destroy']);

        // Exercise routes
        Route::get('/exercises/{exercise}', [\App\Http\Controllers\ExerciseController::class, 'show'])->name('exercises.show');
        Route::resource('exercises', \App\Http\Controllers\ExerciseController::class)->only(['store', 'update', 'destroy']);

        // Body Measurement routes
        Route::resource('body-measurements', \App\Http\Controllers\BodyMeasurementController::class)->only(['store', 'destroy']);
        Route::post('/body-metrics', [\App\Http\Controllers\BodyPartMeasurementController::class, 'store'])->name('body-parts.store');
        Route::delete('/body-metrics/{bodyPartMeasurement}', [\App\Http\Controllers\BodyPartMeasurementController::class, 'destroy'])->name('body-parts.destroy');

        Route::resource('plates', \App\Http\Controllers\PlateController::class)->only(['store', 'update', 'destroy']);
        Route::resource('daily-journals', \App\Http\Controllers\DailyJournalController::class)->only(['store', 'destroy']);

        // Supplement routes
        Route::post('/supplements/{supplement}/consume', [\App\Http\Controllers\SupplementController::class, 'consume'])->name('supplements.consume');
        Route::resource('supplements', \App\Http\Controllers\SupplementController::class)->only(['store', 'update', 'destroy']);

        // Tools routes
        Route::post('/tools/wilks', [\App\Http\Controllers\WilksScoreController::class, 'store'])->name('tools.wilks.store');
        Route::delete('/tools/wilks/{wilksScore}', [\App\Http\Controllers\WilksScoreController::class, 'destroy'])->name('tools.wilks.destroy');

        Route::post('/tools/macro-calculator', [\App\Http\Controllers\MacroCalculatorController::class, 'store'])->name('tools.macro-calculator.store');
        Route::delete('/tools/macro-calculator/{macroCalculation}', [\App\Http\Controllers\MacroCalculatorController::class, 'destroy'])->name('tools.macro-calculator.destroy');

        Route::post('/tools/warmup', [\App\Http\Controllers\WarmupController::class, 'update'])->name('tools.warmup.update');

        Route::post('/tools/water', [\App\Http\Controllers\WaterController::class, 'store'])->name('tools.water.store');
        Route::delete('/tools/water/{waterLog}', [\App\Http\Controllers\WaterController::class, 'destroy'])->name('tools.water.destroy');

        Route::post('/tools/interval-timer', [\App\Http\Controllers\IntervalTimerController::class, 'store'])->name('tools.interval-timer.store');
        Route::patch('/tools/interval-timer/{intervalTimer}', [\App\Http\Controllers\IntervalTimerController::class, 'update'])->name('tools.interval-timer.update');
        Route::delete('/tools/interval-timer/{intervalTimer}', [\App\Http\Controllers\IntervalTimerController::class, 'destroy'])->name('tools.interval-timer.destroy');

        Route::post('/tools/fasting', [\App\Http\Controllers\FastingController::class, 'store'])->name('tools.fasting.store');
        Route::patch('/tools/fasting/{fast}', [\App\Http\Controllers\FastingController::class, 'update'])->name('tools.fasting.update');
        Route::delete('/tools/fasting/{fast}', [\App\Http\Controllers\FastingController::class, 'destroy'])->name('tools.fasting.destroy');
    });
});

// Social Login
Route::get('/auth/{provider}/redirect', [\App\Http\Controllers\Auth\SocialAuthController::class, 'redirect'])
    ->middleware('guest')
    ->name('social.redirect');
Route::get('/auth/{provider}/callback', [\App\Http\Controllers\Auth\SocialAuthController::class, 'callback'])
    ->middleware('guest')
    ->name('social.callback');

/**
 * Le service worker est bâti dans public/build, donc servi depuis /build/sw.js,
 * ce qui lui donne une portée de /build/ : il ne contrôlait aucune page de
 * l'application. Un worker ne peut revendiquer une portée plus large que son
 * propre chemin que si le serveur l'y autorise par cet en-tête.
 */
Route::get('/sw.js', function (): \Symfony\Component\HttpFoundation\Response {
    $worker = public_path('build/sw.js');

    abort_unless(is_file($worker), 404);

    return response()->file($worker, [
        'Content-Type' => 'application/javascript',
        'Service-Worker-Allowed' => '/',
        'Cache-Control' => 'no-cache, must-revalidate',
    ]);
})->name('service-worker');

/*
 * Le plugin PWA precache `manifest.webmanifest` sans préfixe, donc résolu
 * depuis /sw.js en /manifest.webmanifest : servi ici, sinon 404 et Workbox
 * annule l'installation du worker.
 */
Route::get('/manifest.webmanifest', function (): \Symfony\Component\HttpFoundation\Response {
    $manifest = public_path('build/manifest.webmanifest');

    abort_unless(is_file($manifest), 404);

    return response()->file($manifest, [
        'Content-Type' => 'application/manifest+json',
        'Cache-Control' => 'no-cache, must-revalidate',
    ]);
})->name('web-manifest');

/**
 * Raccourci de connexion pour le développement mobile.
 *
 * Ouvre une session sur le compte de démonstration, comme loginAs() le fait
 * dans la suite de tests. Il existe parce qu'un simulateur iOS ne permet pas
 * de saisir un « @ » au clavier, ce qui rend le formulaire de connexion
 * inutilisable pour tester l'application sur appareil.
 *
 * La garde est ici, pas dans le lien qui y mène : hors environnement local la
 * route n'est pas enregistrée, donc il n'y a rien à atteindre même en
 * connaissant l'URL. DevLoginRouteTest le vérifie.
 */
if (app()->environment('local')) {
    Route::get('/__dev-login', function (): \Illuminate\Http\RedirectResponse {
        $user = \App\Models\User::where('email', 'test@example.com')->firstOrFail();

        auth()->login($user);
        request()->session()->regenerate();

        return redirect()->route('dashboard');
    });
}

require __DIR__.'/auth.php';
