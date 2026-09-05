<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Goal;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A goal could be created and then never touched again. The update and destroy
 * routes existed, the policy allowed both, and the API exposed them — but the
 * only page that listed goals offered no way to reach any of it. These drive
 * the controls, not the routes, because routes were never the missing part.
 */
class GoalManagementTest extends DuskTestCase
{
    public function test_a_goal_can_be_edited_from_the_card_that_shows_it(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'title' => 'Objectif mal nommé',
                'type' => 'frequency',
                'exercise_id' => null,
                'target_value' => 50,
                'completed_at' => null,
            ]);

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('goals.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor("@edit-goal-{$goal->id}", 15);

            $browser->clickWhenSettled("[dusk=\"edit-goal-{$goal->id}\"]");
            $browser->waitForLocation("/goals/{$goal->id}/edit", 15)
                ->waitFor('@goal-submit', 15)
                ->type('input[type="text"]:not([type="number"])', 'Objectif renommé');

            $browser->clickWhenSettled('[dusk="goal-submit"]');
            $browser->waitForLocation('/goals', 15);

            $this->assertSame('Objectif renommé', $goal->refresh()->title);
        });
    }

    public function test_a_goal_can_be_deleted_and_asks_first(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            $goal = Goal::factory()->create([
                'user_id' => $user->id,
                'title' => 'Objectif à supprimer',
                'type' => 'frequency',
                'exercise_id' => null,
                'completed_at' => null,
            ]);

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('goals.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor("@delete-goal-{$goal->id}", 15);

            $browser->clickWhenSettled("[dusk=\"delete-goal-{$goal->id}\"]");
            $browser->waitFor('@confirm-delete-goal', 15);

            // The goal survives the dialog opening: deletion is the confirm
            // button's job, not the delete button's.
            $this->assertNotNull(Goal::find($goal->id), 'Opening the dialog must not delete anything.');

            $browser->click('@confirm-delete-goal')
                ->waitUntilMissing("@delete-goal-{$goal->id}", 15);

            $this->assertNull(Goal::find($goal->id));
        });
    }
}
