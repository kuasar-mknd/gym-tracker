<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ExerciseLibraryTest extends DuskTestCase
{
    use DatabaseTruncation;

    /**
     * Test the full lifecycle of exercise management in the library.
     */
    public function test_exercise_library_full_lifecycle(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create([
                'email' => 'library-'.time().'@example.com',
                'password' => bcrypt('password'),
            ]);

            // Create some initial exercises
            Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Bench Press', 'category' => 'Pectoraux']);
            Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Squat', 'category' => 'Jambes']);
            Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Deadlift', 'category' => 'Dos']);

            $browser->loginAs(User::find($user->id))
                ->resizeToIphoneMini()
                ->visit('/exercises')
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            // 1. Check listing
            $browser->assertSee('BENCH PRESS')
                ->assertSee('SQUAT')
                ->assertSee('DEADLIFT');

            // 2. Test Search
            $browser->type('@search-exercises', 'Bench')
                ->pause(500)
                ->assertSee('BENCH PRESS')
                ->assertDontSee('SQUAT')
                ->assertDontSee('DEADLIFT');

            $browser->clear('@search-exercises')
                ->type('@search-exercises', ' ') // Clear properly
                ->keys('@search-exercises', ['{backspace}'])
                ->pause(500);

            // 3. Test Filter by Category
            $browser->click('@category-pill-Jambes')
                ->pause(500)
                ->assertSee('SQUAT')
                ->assertDontSee('BENCH PRESS')
                ->assertDontSee('DEADLIFT');

            $browser->click('@category-pill-all')
                ->pause(500)
                ->assertSee('BENCH PRESS');

            // 4. Create Exercise
            $newExerciseName = 'Pull Up '.time();
            $browser->click('@create-exercise-btn')
                ->waitFor('@exercise-modal-title', 15)
                ->type('@exercise-name-input', $newExerciseName)
                ->select('type', 'strength')
                ->select('form select:last-of-type', 'Dos') // Category select
                ->click('@submit-exercise-btn')
                ->waitUntilMissing('@exercise-modal-title', 15)
                ->waitForText(strtoupper($newExerciseName), 15);

            // 5. Edit Exercise (Inline)
            $exercise = Exercise::where('name', $newExerciseName)->first();
            $updatedName = 'Updated Pull Up '.time();

            // Ensure card is visible
            $browser->script("document.querySelector('[dusk=\"exercise-card-{$exercise->id}\"]').scrollIntoView({block: 'center'});");
            $browser->pause(500);

            // Click the small edit button visible on mobile
            $browser->click("[dusk='edit-exercise-btn-{$exercise->id}']")
                ->waitFor('@edit-exercise-name', 10)
                ->type('@edit-exercise-name', $updatedName)
                ->click('@save-exercise-btn')
                ->waitUntilMissing('@edit-exercise-name', 10)
                ->assertSee(strtoupper($updatedName));

            // 6. Delete Exercise
            // Click the delete button revealed by script (simulating swipe result)
            $browser->script("document.querySelector('[data-testid=\"delete-exercise-button-mobile\"]').scrollIntoView({block: 'center'});");
            $browser->pause(500);
            $browser->script("document.querySelector('[data-testid=\"delete-exercise-button-mobile\"]').click();");

            $browser->acceptDialog()
                ->pause(1000)
                ->assertDontSee(strtoupper($updatedName));
        });
    }

    /**
     * Test responsiveness and layout on different mobile sizes.
     */
    public function test_exercise_library_responsive_layout(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            Exercise::factory()->count(10)->create(['user_id' => $user->id]);

            $sizes = ['resizeToIphoneMini', 'resizeToIphone15', 'resizeToIphoneMax'];

            foreach ($sizes as $size) {
                $browser->loginAs(User::find($user->id))
                    ->{$size}()
                    ->visit('/exercises')
                    ->waitFor('#main-content', 30)
                    ->assertVisible('@create-exercise-btn')
                    ->assertVisible('@search-exercises')
                    ->assertNoConsoleExceptions();
            }
        });
    }
}
