<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Editing a template was a finished feature with no way in. The page, the update
 * action, its request and the policy ability all existed and were wired to the
 * API controller; the two web methods called abort(404) and nothing linked to
 * templates.edit. So the page had never been opened in a browser either — which
 * is the part a feature test cannot cover.
 */
class TemplateEditingTest extends DuskTestCase
{
    public function test_a_template_can_be_renamed_from_the_list(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            $exercise = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Développé couché']);
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id, 'name' => 'Ancien nom']);
            $line = $template->workoutTemplateLines()->create(['exercise_id' => $exercise->id, 'order' => 0]);
            $line->workoutTemplateSets()->create(['reps' => 8, 'weight' => 60, 'is_warmup' => false, 'order' => 0]);

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('templates.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor("@edit-template-{$template->id}", 15)
                ->script("document.querySelector('[dusk=\"edit-template-{$template->id}\"]').click();");

            // Waiting on the field, not the page title: the header truncates with
            // a CSS ellipsis and Selenium reports the clipped string.
            // The form has to arrive filled in, or the round trip would wipe the
            // template rather than edit it.
            $browser->waitFor('input[value="Ancien nom"]', 15)
                ->waitForText('Développé couché', 15)
                ->assertInputValue('input[value="Ancien nom"]', 'Ancien nom');

            $browser->script(
                'const field = document.querySelector(\'input[value="Ancien nom"]\');'
                .'field.value = "Nouveau nom";'
                .'field.dispatchEvent(new Event("input", {bubbles: true}));'
            );

            // Two reasons this is not press('Mettre à jour le modèle'): Dusk
            // matches a button on its own text and the label lives in
            // GlassButton's slot, and at the page's resting scroll position the
            // round floating action button sits over the submit button's centre,
            // which is where WebDriver aims. Measured, not guessed: the
            // interception names a material-symbols span, and after this scroll
            // elementFromPoint at the same centre returns the submit button.
            // Not waitForText afterwards: the card's heading is text-transform
            // uppercase and Selenium reports the transformed string, so the name
            // is asserted against the database instead.
            $browser->clickWhenSettled('button[type="submit"]')
                ->waitForLocation('/templates', 15)
                ->waitFor("@edit-template-{$template->id}", 15);

            $this->assertSame('Nouveau nom', $template->refresh()->name);
            $this->assertCount(
                1,
                $template->workoutTemplateLines,
                'Saving the form must keep the exercise it arrived with.'
            );
        });
    }

    public function test_the_edit_and_delete_controls_are_named(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id, 'name' => 'Haut du corps']);

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('templates.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor("@edit-template-{$template->id}", 15);

            $names = $browser->script(
                "return ['edit', 'delete'].map(kind =>"
                ." document.querySelector(`[dusk=\"\${kind}-template-{$template->id}\"]`)"
                .'.getAttribute("aria-label"));'
            )[0];

            // The delete button carried only title=, which a touch device never
            // reveals because there is no hover to trigger it.
            $this->assertSame(['Modifier Haut du corps', 'Supprimer Haut du corps'], $names);
        });
    }
}
