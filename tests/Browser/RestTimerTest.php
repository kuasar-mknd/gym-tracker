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

class RestTimerTest extends DuskTestCase
{
    use DatabaseTruncation;

    private function setupWorkout(int $restTime = 60): array
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
        $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength', 'default_rest_time' => $restTime]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
        $set = Set::factory()->create(['workout_line_id' => $line->id, 'is_completed' => false, 'weight' => 50, 'reps' => 10]);

        return [$user, $workout, $line, $set];
    }

    private function performTimerLifecycle(Browser $browser, string $sizeMacro): void
    {
        [$user, $workout] = $this->setupWorkout();

        $browser->loginAs($user->id)
            ->{$sizeMacro}()
            ->visit("/workouts/{$workout->id}")
            ->disableAnimations()
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
    }

    public function test_timer_lifecycle_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performTimerLifecycle($browser, 'resizeToIphoneMini');
        });
    }

    public function test_timer_lifecycle_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performTimerLifecycle($browser, 'resizeToIphone15');
        });
    }

    public function test_timer_lifecycle_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performTimerLifecycle($browser, 'resizeToIphoneMax');
        });
    }

    public function test_timer_add_time_on_iphone_mini(): void
    {
        [$user, $workout] = $this->setupWorkout(30);

        $this->browse(function (Browser $browser) use ($user, $workout): void {
            $browser->loginAs($user->id)
                ->resizeToIphoneMini()
                ->visit("/workouts/{$workout->id}")
                ->disableAnimations()
                ->waitFor('@complete-set-0-0', 15)
                ->click('@complete-set-0-0')
                ->waitFor('@rest-timer', 10);

            // Mettre en pause pour avoir un temps stable
            $browser->click('button[aria-label="Pause"]')
                ->pause(500)
                ->waitFor('[role="timer"]', 10);

            $timeBefore = $browser->text('[role="timer"]');

            // Cliquer sur +30s
            $browser->click('button[aria-label="Ajouter 30 secondes"]')
                ->pause(500);

            $timeAfter = $browser->text('[role="timer"]');

            $this->assertNotEquals($timeBefore, $timeAfter);
            $browser->assertSee(':');
        });
    }
}
