<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WorkoutSessionE2ETest extends DuskTestCase
{
    use DatabaseTruncation;

    private function performFullWorkout(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john'.time().random_int(0, 9999).'@example.com',
            'email_verified_at' => now(),
        ]);

        // EDGE CASE PRE-REQUISITE: Multiple past workouts
        $recommenderEx = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength', 'name' => 'Recommender Ex']);

        // 1. Very old workout (3 days ago) - Should be ignored
        $oldestWorkout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(3),
            'ended_at' => now()->subDays(3)->addHour(),
        ]);
        $oldestLine = WorkoutLine::factory()->create(['workout_id' => $oldestWorkout->id, 'exercise_id' => $recommenderEx->id]);
        Set::factory()->count(5)->create(['workout_line_id' => $oldestLine->id, 'weight' => 120, 'reps' => 2, 'is_completed' => true]);

        // 2. Most recent past workout (2 days ago) - Should be the source
        $lastWorkout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(2),
            'ended_at' => now()->subDays(2)->addHour(),
        ]);
        $lastLine = WorkoutLine::factory()->create(['workout_id' => $lastWorkout->id, 'exercise_id' => $recommenderEx->id]);

        // Distribution in last workout:
        // - 110kg x 3 reps (Frequency: 3) -> TARGET
        // - 100kg x 5 reps (Frequency: 2)
        Set::factory()->count(3)->create(['workout_line_id' => $lastLine->id, 'weight' => 110, 'reps' => 3, 'is_completed' => true]);
        Set::factory()->count(2)->create(['workout_line_id' => $lastLine->id, 'weight' => 100, 'reps' => 5, 'is_completed' => true]);

        $strengthEx = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength', 'name' => 'Strength Ex']);
        $cardioEx = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'cardio', 'name' => 'Cardio Ex']);
        $timedEx = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'timed', 'name' => 'Timed Ex']);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
            'name' => 'Séance Test',
        ]);

        try {
            $browser->loginAs($user)
                ->{$sizeMacro}()
                ->visit("/workouts/{$workout->id}")
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            // 1. Add Strength exercise
            $browser->waitFor('[dusk="add-first-exercise"]', 15)->click('[dusk="add-first-exercise"]');
            $browser->waitFor('input[placeholder="Rechercher..."]', 15)->type('input[placeholder="Rechercher..."]', 'Strength Ex')
                ->waitFor('@select-exercise-'.$strengthEx->id, 20)->click('@select-exercise-'.$strengthEx->id);
            $browser->waitUntilMissing('[role="dialog"]', 15);

            // 2. Add Cardio exercise
            $browser->waitFor('[dusk="add-exercise-existing"]', 15)
                ->script("document.querySelector('[dusk=\"add-exercise-existing\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-exercise-existing"]');
            $browser->waitFor('input[placeholder="Rechercher..."]', 15)->type('input[placeholder="Rechercher..."]', 'Cardio Ex')
                ->waitFor('@select-exercise-'.$cardioEx->id, 20)->click('@select-exercise-'.$cardioEx->id);
            $browser->waitUntilMissing('[role="dialog"]', 15);

            // 3. Add Timed exercise
            $browser->waitFor('[dusk="add-exercise-existing"]', 15)
                ->script("document.querySelector('[dusk=\"add-exercise-existing\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-exercise-existing"]');
            $browser->waitFor('input[placeholder="Rechercher..."]', 15)->type('input[placeholder="Rechercher..."]', 'Timed Ex')
                ->waitFor('@select-exercise-'.$timedEx->id, 20)->click('@select-exercise-'.$timedEx->id);
            $browser->waitUntilMissing('[role="dialog"]', 15);

            // 3b. Create NEW exercise from workout
            $browser->waitFor('[dusk="add-exercise-existing"]', 15)
                ->script("document.querySelector('[dusk=\"add-exercise-existing\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-exercise-existing"]');
            $browser->waitFor('input[placeholder="Rechercher..."]', 15)->type('input[placeholder="Rechercher..."]', 'Brand New Exercise');
            $browser->waitFor('@quick-create-exercise', 15)->click('@quick-create-exercise');
            $browser->waitFor('@new-exercise-name', 15)
                ->assertInputValue('@new-exercise-name', 'Brand New Exercise')
                ->select('@new-exercise-type', 'strength')
                ->select('@new-exercise-category', 'Pectoraux')
                ->click('@submit-new-exercise');
            $browser->waitUntilMissing('[role="dialog"]', 20);

            // 3c. Add Recommender Exercise (TESTING VALUES FROM LAST WORKOUT)
            $browser->waitFor('[dusk="add-exercise-existing"]', 15)
                ->script("document.querySelector('[dusk=\"add-exercise-existing\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-exercise-existing"]');
            $browser->waitFor('input[placeholder="Rechercher..."]', 15)->type('input[placeholder="Rechercher..."]', 'Recommender Ex')
                ->waitFor('@select-exercise-'.$recommenderEx->id, 20)->click('@select-exercise-'.$recommenderEx->id);
            $browser->waitUntilMissing('[role="dialog"]', 15);

            // Wait for all cards to be present and stabilize
            $browser->waitFor('@exercise-card-4', 25)
                ->pause(2000) // Give API responses time to settle so Vue doesn't remount components mid-interaction
                ->assertSee('BRAND NEW EXERCISE')
                ->assertSee('RECOMMENDER EX');

            // 4. Fill Strength set
            $html = $browser->script('return document.body.innerHTML;')[0];
            file_put_contents(storage_path('logs/dusk_html_dump.html'), $html);

            $browser->waitFor('@add-set-0', 15);
            $browser->script("document.querySelector('[dusk=\"add-set-0\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-set-0"]')
                ->waitFor('@weight-input-0-0', 15)
                ->type('@weight-input-0-0', '80')
                ->type('@reps-input-0-0', '5');

            // 5. Fill Cardio set
            $browser->waitFor('@add-set-1', 15);
            $browser->script("document.querySelector('[dusk=\"add-set-1\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-set-1"]')
                ->waitFor('@distance-input-1-0', 15)
                ->type('@distance-input-1-0', '5.5')
                ->type('@duration-input-1-0', '002530');

            // 6. Fill Timed set
            $browser->waitFor('@add-set-2', 15);
            $browser->script("document.querySelector('[dusk=\"add-set-2\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-set-2"]')
                ->waitFor('@duration-input-2-0', 15)
                ->type('@duration-input-2-0', '001000');

            // 6b. Verify RECOMMENDED values for Recommender Ex (Index 4)
            // It should be 110kg x 3 reps (most frequent in the LAST workout)
            $browser->waitFor('@add-set-4', 15);
            $browser->script("document.querySelector('[dusk=\"add-set-4\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-set-4"]')
                ->waitFor('@weight-input-4-0', 15)
                ->assertInputValue('@weight-input-4-0', '110')
                ->assertInputValue('@reps-input-4-0', '3');

            // 6c. Add an extra set to Strength Ex, then delete it
            $browser->script("document.querySelector('[dusk=\"add-set-0\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-set-0"]')
                ->waitFor('@weight-input-0-1', 15)
                ->type('@weight-input-0-1', '85')
                ->type('@reps-input-0-1', '3')
                ->pause(1500) // Wait for debounce to save
                ->script("document.querySelector('[dusk=\"remove-set-0-1\"]').scrollIntoView({block: 'center'});");
            $browser->waitFor('@remove-set-0-1', 10)
                ->click('@remove-set-0-1')
                ->waitUntilMissing('@weight-input-0-1', 15);

            // 6d. Modify workout settings
            $browser->script("window.scrollTo({ top: 0, behavior: 'smooth' });");
            $browser->waitFor('@workout-settings-button', 15)
                ->pause(500)
                ->click('@workout-settings-button')
                ->waitFor('@workout-name-input', 15)
                ->clear('@workout-name-input')
                ->type('@workout-name-input', 'Workout Updated')
                ->click('@save-settings-button')
                ->waitUntilMissing('[role="dialog"]', 15)
                ->pause(1000)
                ->assertSee('WORKOUT UPDATED');

            // 6e. Delete Cardio exercise (Index 1)
            $browser->script("document.querySelector('[dusk=\"remove-line-1\"]').scrollIntoView({block: 'center'});");

            // Get the stable ID of the cardio line before deleting it
            $cardioLineId = $browser->attribute('[dusk="exercise-card-1"]', 'data-line-id');

            $browser->click('@remove-line-1')
                ->waitFor('@confirm-delete-button', 15)
                ->pause(500)
                ->click('@confirm-delete-button')
                ->waitUntilMissing("[dusk-id=\"exercise-line-{$cardioLineId}\"]", 15);

            $browser->within('@exercise-list', function (Browser $list) use ($cardioLineId): void {
                $list->assertDontSee('CARDIO EX');
                $list->assertMissing("[dusk-id=\"exercise-line-{$cardioLineId}\"]");
            });

            // 7. Complete one set and verify PR trophy
            $browser->script("document.querySelector('[dusk=\"exercise-card-0\"]').scrollIntoView({block: 'start'});");
            $browser->pause(1000);
            $browser->click('[dusk="complete-set-0-0"]')
                ->pause(2000)
                ->waitFor('@pr-trophy-0-0', 20)
                ->waitFor('[dusk="skip-rest-timer"]', 15)
                ->click('[dusk="skip-rest-timer"]');

            $browser->pause(1000);

            // 8. Finish Workout
            $browser->waitFor('@finish-workout-mobile', 15)
                ->script("document.querySelector('[dusk=\"finish-workout-mobile\"]').scrollIntoView({block: 'center'});");
            $browser->pause(500)
                ->click('@finish-workout-mobile');

            $browser->waitFor('@finish-workout-modal-title', 15)
                ->waitFor('#confirm-finish-button', 15)
                ->pause(1000)
                ->click('#confirm-finish-button');

            $browser->waitUsing(15, 500, fn (): bool => \App\Models\Workout::find($workout->id)->ended_at !== null);

            $browser->visit('/dashboard')
                ->waitFor('#dashboard-header', 30)
                ->assertSee('RETOUR')
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('workout-failure-'.$sizeMacro);
            throw $e;
        }
    }

    public function test_workout_session_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performFullWorkout($browser, 'resizeToIphoneMini');
        });
    }

    public function test_workout_session_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performFullWorkout($browser, 'resizeToIphone15');
        });
    }

    public function test_workout_session_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performFullWorkout($browser, 'resizeToIphoneMax');
        });
    }
}
