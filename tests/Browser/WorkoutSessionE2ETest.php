<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;

uses(DatabaseTruncation::class);

test('ultra complete workout session flow on different iphone sizes', function (string $sizeMacro): void {
    $user = User::factory()->create([
        'name' => 'John Doe',
        'email' => 'john'.time().'@example.com',
        'password' => bcrypt('password123'),
    ]);

    $exercises = Exercise::factory()->count(2)->create([
        'user_id' => $user->id,
        'type' => 'strength',
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now(),
        'name' => 'Séance Test',
    ]);

    $this->browse(function (Browser $browser) use ($user, $exercises, $workout, $sizeMacro): void {
        try {
            $browser->loginAs($user)
                ->{$sizeMacro}()
                ->visit("/workouts/{$workout->id}")
                ->waitFor('main', 30)
                // Disable CSS transitions for test stability
                ->script("
                    const style = document.createElement('style');
                    style.innerHTML = '* { transition: none !important; animation: none !important; }';
                    document.head.appendChild(style);
                ");

            // 1. Add exercise
            $browser->waitFor('@add-first-exercise', 30)
                ->script("document.querySelector('[dusk=\"add-first-exercise\"]').click();");

            $browser->waitFor('input[placeholder="Rechercher..."]', 30)
                ->type('input[placeholder="Rechercher..."]', $exercises[0]->name)
                ->pause(2000)
                ->waitFor('@select-exercise-'.$exercises[0]->id, 20)
                ->script("document.querySelector('[dusk=\"select-exercise-{$exercises[0]->id}\"]').click();");

            // Wait for card
            $browser->waitFor('@exercise-card-0', 30);

            // 2. Click Add Set
            $browser->script("document.querySelector('[dusk=\"add-set-0\"]').click();");

            // 3. Wait for the new set
            $browser->waitFor('@weight-input-0-0', 30);

            // 4. Fill values
            $browser->type('@weight-input-0-0', '80')
                ->pause(500)
                ->type('@reps-input-0-0', '5')
                ->pause(500);

            // Hide keyboard/unfocus to ensure visibility
            $browser->script('document.activeElement.blur();');
            $browser->pause(2000); // EXTRA PAUSE FOR DEBOUNCE

            // 5. Complete set (using JS click for mobile reliability)
            $browser->waitFor('@complete-set-0-0', 30)
                ->script("document.querySelector('[dusk=\"complete-set-0-0\"]').click();");

            // Wait for rest timer to appear and skip it
            $browser->waitFor('[dusk="skip-rest-timer"]', 30)
                ->pause(2000)
                ->click('[dusk="skip-rest-timer"]')
                ->pause(2000);
            // 6. Finish Workout
            $browser->waitFor('#finish-workout-mobile', 30)
                ->pause(2000)
                ->script("document.getElementById('finish-workout-mobile').click();");

            // Wait for modal and confirm button
            $browser->waitFor('@finish-workout-modal-title', 30)
                ->waitFor('#confirm-finish-button', 30)
                ->pause(2000)
                ->script("document.getElementById('confirm-finish-button').click();");

            // 7. Verify
            $browser->screenshot('debug-after-finish-'.$sizeMacro)
                ->waitForText('BON RETOUR', 150)
                ->assertSee('BON RETOUR')
                ->assertPathIs('/dashboard')
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('workout-failure-'.$sizeMacro);
            throw $e;
        }
    });
})->with([
    'iPhone Mini' => 'resizeToIphoneMini',
    'iPhone 15' => 'resizeToIphone15',
    'iPhone Pro Max' => 'resizeToIphoneMax',
]);
