<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;

uses(DatabaseTruncation::class);

/**
 * TEST: Simulation d'une race condition entre saisie locale et refresh Inertia.
 * Le but est de prouver que si on tape une valeur et qu'un refresh arrive AVANT
 * la fin de la synchro Axios, la valeur locale n'est PAS écrasée.
 */
test('local edits are preserved during inertia refresh race on different iphone sizes', function (string $sizeMacro): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    $set = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 50, 'reps' => 10]);

    $this->browse(function (Browser $browser) use ($user, $workout, $set, $sizeMacro): void {
        $browser->loginAs($user)
            ->{$sizeMacro}()
            ->visit("/workouts/{$workout->id}")
            ->pause(3000);

        $browser->waitFor('@weight-input-0-0', 10);

        // 1. On focus l'input et on change la valeur (50 -> 99)
        $browser->click('@weight-input-0-0')
            ->type('@weight-input-0-0', '99');

        // 2. RACE CONDITION: On déclenche un refresh Inertia IMMEDIATEMENT
        $browser->script('window.Inertia.reload()');

        // On attend un peu pour laisser le temps au JS de faire ses bêtises
        $browser->pause(2000);

        // 3. VERIFICATION: La valeur doit TOUJOURS être 99 dans l'input
        $value = $browser->inputValue('@weight-input-0-0');

        if ($value !== '99') {
            $browser->screenshot('race-condition-failed-'.$sizeMacro);
        }

        expect($value)->toBe('99', 'La valeur locale a été écrasée par les props du serveur lors du refresh !');

        // 4. On quitte le champ (blur)
        $browser->keys('@weight-input-0-0', '{tab}');

        // On attend la fin du debounce et de la synchro réelle
        $browser->pause(1000);

        // On vérifie en DB que le 99 a fini par arriver
        expect($set->fresh()->weight)->toBe(99);
    });
})->with([
    'iPhone Mini' => 'resizeToIphoneMini',
    'iPhone 15' => 'resizeToIphone15',
    'iPhone Pro Max' => 'resizeToIphoneMax',
]);
