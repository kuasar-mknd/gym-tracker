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
            $browser->waitFor('@add-first-exercise', 15)
                ->script("document.querySelector('[dusk=\"add-first-exercise\"]').click();");

            $browser->waitFor('input[placeholder="Rechercher..."]', 15)
                ->type('input[placeholder="Rechercher..."]', $exercises[0]->name)
                ->pause(1500)
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
            $browser->waitFor('@complete-set-0-0', 15)
                ->script("document.querySelector('[dusk=\"complete-set-0-0\"]').click();");

            // Wait for rest timer to appear and skip it
            $browser->waitFor('[dusk="skip-rest-timer"]', 15)
                ->pause(1000)
                ->click('[dusk="skip-rest-timer"]')
                ->pause(1000);
            // 6. Finish Workout
            $browser->waitFor('#finish-workout-mobile', 15)
                ->pause(1000)
                ->script("document.querySelector('#finish-workout-mobile').click();");

            // Wait for modal and confirm button
            $browser->waitForText('TERMINER LA SÉANCE', 15)
                ->waitFor('#confirm-finish-button', 15)
                ->pause(2000)
                ->click('#confirm-finish-button');

            // 7. Verify
            $browser->waitForLocation('/dashboard', 120)
                ->waitForText('BON RETOUR', 30)
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
