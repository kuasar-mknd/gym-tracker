<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Fast;
use App\Models\User;
use App\Models\Workout;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Two lists paginate server-side and rendered no way to leave page one, so the
 * rows past the cut existed in the database and could not be opened. The
 * notifications list had the same defect and was fixed earlier in this branch;
 * these two were still carrying it.
 */
class PaginationReachabilityTest extends DuskTestCase
{
    public function test_the_workout_history_can_be_paged_past_the_first_twenty(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            // 25 finished sessions: 20 on page one, 5 stranded without this.
            collect(range(1, 25))->each(fn (int $index) => Workout::factory()->create([
                'user_id' => $user->id,
                'name' => "Séance {$index}",
                'started_at' => now()->subDays($index),
                'ended_at' => now()->subDays($index)->addHour(),
            ]));

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('workouts.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@workouts-next', 15);

            $firstPage = $browser->script(
                'return Array.from(document.querySelectorAll(\'[dusk^="delete-workout-"]\'))'
                .'.map(el => el.getAttribute("dusk"));'
            )[0];

            $browser->clickWhenSettled('[dusk="workouts-next"]');
            $browser->waitForTextIn('nav[aria-label="Pagination de l\'historique"]', 'Page 2', 15);

            $secondPage = $browser->script(
                'return Array.from(document.querySelectorAll(\'[dusk^="delete-workout-"]\'))'
                .'.map(el => el.getAttribute("dusk"));'
            )[0];

            $this->assertCount(20, $firstPage, 'The first page holds the paginator size.');
            $this->assertNotEmpty($secondPage, 'Page 2 must render the remaining sessions.');
            $this->assertEmpty(
                array_intersect($firstPage, $secondPage),
                'Page 2 must show different sessions than page 1.'
            );
        });
    }

    /**
     * L'historique de vingt-trois séances se lit sur deux pages : vingt cartes
     * sur la première, trois sur la seconde, atteinte par « Page suivante ».
     */
    public function test_the_workout_history_spans_two_pages(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            collect(range(1, 23))->each(fn (int $index) => Workout::factory()->create([
                'user_id' => $user->id,
                'started_at' => now()->subDays($index),
                'ended_at' => now()->subDays($index)->addHour(),
            ]));

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('workouts.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@workouts-next', 15)
                ->assertScript('document.querySelectorAll(\'[dusk^="delete-workout-"]\').length', 20)
                ->assertSee('Page 1 sur 2')
                ->click('@workouts-next')
                ->waitForText('Page 2 sur 2', 15)
                ->assertScript('document.querySelectorAll(\'[dusk^="delete-workout-"]\').length', 3);
        });
    }

    public function test_the_fasting_history_can_be_paged_past_the_first_ten(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            collect(range(1, 14))->each(fn (int $index) => Fast::create([
                'user_id' => $user->id,
                'type' => '16:8',
                'target_duration_minutes' => 960,
                'start_time' => now()->subDays($index + 1),
                'end_time' => now()->subDays($index + 1)->addHours(16),
                'status' => 'completed',
            ]));

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('tools.fasting.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@fasting-next', 15);

            $browser->clickWhenSettled('[dusk="fasting-next"]');
            $browser->waitForTextIn('nav[aria-label="Pagination de l\'historique des jeûnes"]', 'Page 2', 15);

            $onPageTwo = $browser->script(
                'return document.querySelectorAll(\'[aria-label^="Supprimer le jeûne"]\').length;'
            )[0];

            $this->assertGreaterThan(0, $onPageTwo, 'Page 2 must render the remaining fasts.');
        });
    }
}
