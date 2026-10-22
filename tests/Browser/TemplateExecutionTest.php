<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * "Lancer cette séance" posted straight to templates.execute with no processing
 * state, so an impatient double tap — routine on a phone mid-session — used to
 * create two workouts the user then had to delete by hand. Since #2049 it
 * launches through the offline queue (`useDemarrageDUneSeance`, one
 * `POST /api/v1/workouts` with the template), behind the same guard.
 */
class TemplateExecutionTest extends DuskTestCase
{
    public function test_double_tapping_launch_creates_a_single_workout(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('templates.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor("@execute-template-{$template->id}", 10);

            // Two clicks dispatched in the same tick: a plain ->click()->click()
            // would let the first navigation land in between and never exercise
            // the guard.
            $browser->script(
                "document.querySelectorAll('[dusk=\"execute-template-{$template->id}\"]')"
                ."[0].click();\n"
                ."document.querySelectorAll('[dusk=\"execute-template-{$template->id}\"]')"
                .'[0].click();'
            );

            // L'écran ne dit pas que la séance existe : sans réponse du serveur
            // en cinq secondes, la page ouvre la séance en attente
            // (/workouts/en-attente/…), construite depuis la file, et ce chemin
            // remplit aussi cette condition. Compter aussitôt lisait alors la
            // base avant l'écriture (#2100).
            $browser->waitUntil('window.location.pathname.startsWith("/workouts/")', 15);

            // La séance s'attend donc en base avant d'être comptée.
            $browser->waitUsing(
                15,
                100,
                fn (): bool => Workout::where('user_id', $user->id)->exists(),
                'Après %s s, aucune séance n’existe en base pour ce compte : le lancement n’a pas atteint le serveur.',
            );

            $this->assertSame(
                1,
                Workout::where('user_id', $user->id)->count(),
                'A double tap on "Lancer cette séance" must not create a second workout.'
            );
        });
    }

    public function test_launch_button_reports_progress_while_posting(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();
            $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('templates.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor("@execute-template-{$template->id}", 10)
                ->assertAttributeMissing("@execute-template-{$template->id}", 'disabled');
        });
    }
}
