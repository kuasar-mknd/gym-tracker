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

test('timer lifecycle on different iphone sizes', function (string $sizeMacro): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength', 'default_rest_time' => 60]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    $set = Set::factory()->create(['workout_line_id' => $line->id, 'is_completed' => false, 'weight' => 50, 'reps' => 10]);

    $this->browse(function (Browser $browser) use ($user, $workout, $sizeMacro): void {
        $browser->loginAs($user)
            ->{$sizeMacro}()
            ->visit("/workouts/{$workout->id}")
            ->waitFor('@complete-set-0-0', 15);

        // 1. On vérifie que le timer n'est pas là au départ
        $browser->assertMissing('@rest-timer');

        // 2. On complète la série (ce qui déclenche le timer)
        $browser->click('@complete-set-0-0')
            ->pause(1000);

        // 3. On vérifie que le timer est apparu
        $browser->waitFor('@rest-timer', 10)
            ->assertVisible('@rest-timer')
            ->assertSee('REPOS EN COURS');

        // 4. On clique sur Fermer (bouton du bas)
        $browser->click('@close-timer')
            ->pause(500)
            ->assertMissing('@rest-timer');

        // 6. On le redéclenche (en décochant/re-cochant la série)
        $browser->click('@complete-set-0-0') // décoche
            ->pause(500)
            ->click('@complete-set-0-0') // re-coche
            ->waitFor('@rest-timer', 10);

        // 7. On clique sur le bouton X (en haut à droite)
        $browser->waitFor('@close-timer-x', 5)
            ->click('@close-timer-x')
            ->pause(500)
            ->assertMissing('@rest-timer');
    });
})->with([
    'iPhone Mini' => 'resizeToIphoneMini',
    'iPhone 15' => 'resizeToIphone15',
    'iPhone Pro Max' => 'resizeToIphoneMax',
]);

test('timer add time on different iphone sizes', function (string $sizeMacro): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength', 'default_rest_time' => 90]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    $set = Set::factory()->create(['workout_line_id' => $line->id, 'is_completed' => false, 'weight' => 50, 'reps' => 10]);

    $this->browse(function (Browser $browser) use ($user, $workout, $sizeMacro): void {
        $browser->loginAs($user)
            ->{$sizeMacro}()
            ->visit("/workouts/{$workout->id}")
            ->waitFor('@complete-set-0-0', 15)
            ->click('@complete-set-0-0')
            ->waitFor('@rest-timer', 10);

        // Mettre en pause pour avoir un temps stable
        $browser->click('button[aria-label="Pause"]')
            ->pause(500);

        $timeBefore = $browser->text('[role="timer"]');

        // Cliquer sur +30s
        $browser->click('button[aria-label="Ajouter 30 secondes"]')
            ->pause(500);

        $timeAfter = $browser->text('[role="timer"]');

        expect($timeBefore)->not->toBe($timeAfter);
        $browser->assertSee(':');
    });
})->with([
    'iPhone Mini' => 'resizeToIphoneMini',
    'iPhone 15' => 'resizeToIphone15',
    'iPhone Pro Max' => 'resizeToIphoneMax',
]);

test('timer pause resume on different iphone sizes', function (string $sizeMacro): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength', 'default_rest_time' => 60]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    $set = Set::factory()->create(['workout_line_id' => $line->id, 'is_completed' => false, 'weight' => 50, 'reps' => 10]);

    $this->browse(function (Browser $browser) use ($user, $workout, $sizeMacro): void {
        $browser->loginAs($user)
            ->{$sizeMacro}()
            ->visit("/workouts/{$workout->id}")
            ->waitFor('@complete-set-0-0', 15)
            ->click('@complete-set-0-0')
            ->waitFor('@rest-timer', 10);

        // Mettre en pause
        $browser->click('button[aria-label="Pause"]')
            ->pause(500);

        $timeAtPause = $browser->text('[role="timer"]');

        // Attendre un peu
        $browser->pause(2000);

        // Vérifier que le temps n'a pas bougé
        expect($browser->text('[role="timer"]'))->toBe($timeAtPause);

        // Reprendre
        $browser->click('button[aria-label="Démarrer le minuteur"]')
            ->pause(2000);

        // Vérifier que le temps a diminué
        expect($browser->text('[role="timer"]'))->not->toBe($timeAtPause);
    });
})->with([
    'iPhone Mini' => 'resizeToIphoneMini',
    'iPhone 15' => 'resizeToIphone15',
    'iPhone Pro Max' => 'resizeToIphoneMax',
]);

test('timer skip on different iphone sizes', function (string $sizeMacro): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength', 'default_rest_time' => 60]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    $set = Set::factory()->create(['workout_line_id' => $line->id, 'is_completed' => false, 'weight' => 50, 'reps' => 10]);

    $this->browse(function (Browser $browser) use ($user, $workout, $sizeMacro): void {
        $browser->loginAs($user)
            ->{$sizeMacro}()
            ->visit("/workouts/{$workout->id}")
            ->waitFor('@complete-set-0-0', 15)
            ->click('@complete-set-0-0')
            ->waitFor('@rest-timer', 10);

        // Cliquer sur Passer
        $browser->click('@skip-rest-timer')
            ->pause(1000);

        // Le timer doit disparaître
        $browser->assertMissing('@rest-timer');
    });
})->with([
    'iPhone Mini' => 'resizeToIphoneMini',
    'iPhone 15' => 'resizeToIphone15',
    'iPhone Pro Max' => 'resizeToIphoneMax',
]);

test('timer finishes automatically on different iphone sizes', function (string $sizeMacro): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
    // 3 secondes de repos
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength', 'default_rest_time' => 3]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
    $set = Set::factory()->create(['workout_line_id' => $line->id, 'is_completed' => false, 'weight' => 50, 'reps' => 10]);

    $this->browse(function (Browser $browser) use ($user, $workout, $sizeMacro): void {
        $browser->loginAs($user)
            ->{$sizeMacro}()
            ->visit("/workouts/{$workout->id}")
            ->waitFor('@complete-set-0-0', 15)
            ->click('@complete-set-0-0')
            ->waitFor('@rest-timer', 10);

        // Attendre que le timer finisse (3s + marge)
        $browser->pause(4000);

        // Le timer doit avoir disparu
        $browser->assertMissing('@rest-timer');
    });
})->with([
    'iPhone Mini' => 'resizeToIphoneMini',
    'iPhone 15' => 'resizeToIphone15',
    'iPhone Pro Max' => 'resizeToIphoneMax',
]);
