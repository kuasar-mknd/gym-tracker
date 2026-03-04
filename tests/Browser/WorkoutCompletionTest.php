<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;

uses(DatabaseTruncation::class);

test('user can finish workout and is redirected on different iphone sizes', function (string $sizeMacro): void {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'name' => 'Séance Test Browser',
        'started_at' => now()->subHour(),
    ]);

    // Add an exercise line so the finish button is visible
    $exercise = Exercise::factory()->create(['user_id' => $user->id]);
    WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $this->browse(function (Browser $browser) use ($user, $workout, $sizeMacro): void {
        $browser->loginAs($user)
            ->{$sizeMacro}()
            ->visit('/workouts/'.$workout->id)
            ->waitFor('main', 30)
            ->assertPathIs('/workouts/'.$workout->id)
            ->assertNoConsoleExceptions()
            ->waitFor('#finish-workout-mobile', 30)
            ->script("document.querySelector('#finish-workout-mobile').click();");

        $browser->waitFor('#confirm-finish-button', 30)
            ->pause(1000)
            ->script("document.getElementById('confirm-finish-button').click();");

        $browser->waitForText('BON RETOUR', 45)
            ->assertPathIs('/dashboard');
    });
})->with([
    'iPhone Mini' => 'resizeToIphoneMini',
    'iPhone 15' => 'resizeToIphone15',
    'iPhone Pro Max' => 'resizeToIphoneMax',
]);

test('finished workout is immutable in ui on different iphone sizes', function (string $sizeMacro): void {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'name' => 'Immutable Workout',
        'started_at' => now()->subHour(),
        'ended_at' => now(),
    ]);

    $this->browse(function (Browser $browser) use ($user, $workout, $sizeMacro): void {
        $browser->loginAs($user)
            ->{$sizeMacro}()
            ->visit('/workouts/'.$workout->id)
            ->waitFor('main', 30)
            ->assertNoConsoleExceptions()
            ->assertMissing('#finish-workout-mobile');
    });
})->with([
    'iPhone Mini' => 'resizeToIphoneMini',
    'iPhone 15' => 'resizeToIphone15',
    'iPhone Pro Max' => 'resizeToIphoneMax',
]);
