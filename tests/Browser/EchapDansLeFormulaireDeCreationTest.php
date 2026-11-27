<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Models\Workout;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Dans le tiroir « Ajouter un exercice » ouvert sur une séance, Échap pris dans
 * le formulaire de création ramène à la liste sans fermer le tiroir ; un second
 * Échap, dans la liste, le ferme.
 *
 * Le focus part d'une zone vide du formulaire, hors de tout champ : c'est là que
 * le navigateur prend Échap, et la demande de fermeture native doit être refusée
 * par `surAnnulation` (`preventDefault`) tant qu'on est dans le formulaire.
 */
class EchapDansLeFormulaireDeCreationTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_escape_in_the_create_form_goes_back_to_the_list_then_escape_closes_the_drawer(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create(['email_verified_at' => now()]);
            $seance = Workout::factory()->create([
                'user_id' => $user->id,
                'started_at' => now(),
                'name' => 'Séance Échap',
            ]);

            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit("/workouts/{$seance->id}")
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('[dusk="add-first-exercise"]', 15)
                ->click('[dusk="add-first-exercise"]');

            $browser->waitFor('input[placeholder="Rechercher..."]', 15)
                ->type('input[placeholder="Rechercher..."]', 'Exercice absent de la bibliothèque')
                ->waitFor('@quick-create-exercise', 15)
                ->script('document.querySelector(\'[dusk="quick-create-exercise"]\').click();');

            $browser->waitFor('@new-exercise-name', 15)->waitForStableLayout();

            // Décalage, depuis le centre du formulaire, d'un point qui n'est ni un champ ni un bouton.
            $zoneVide = $browser->script(<<<'JS'
                const formulaire = document.querySelector('dialog[open] form');
                const r = formulaire.getBoundingClientRect();
                const controles = 'input,textarea,select,button,label,a';
                for (let y = r.top + 2; y < r.bottom; y += 4) {
                    for (let x = r.left + 2; x < r.right; x += 4) {
                        const el = document.elementFromPoint(x, y);
                        if (el && formulaire.contains(el) && ! el.closest(controles)) {
                            return { dx: x - (r.left + r.width / 2), dy: y - (r.top + r.height / 2) };
                        }
                    }
                }
                return null;
            JS)[0];

            $this->assertIsArray($zoneVide, 'Le formulaire de création doit avoir une zone vide où poser le focus.');

            $enEntier = static fn (mixed $decalage): int => is_int($decalage) || is_float($decalage) ? (int) round($decalage) : 0;

            // Relève où le clic tombe réellement, pour le prouver hors champ.
            $browser->script(<<<'JS'
                window.__cliqueDuTest = null;
                document.addEventListener('mousedown', (e) => {
                    window.__cliqueDuTest = { tag: e.target.tagName, dansFormulaire: !! e.target.closest('dialog[open] form') };
                }, { capture: true, once: true });
            JS);

            $browser->driver->action()
                ->moveToElement(
                    $browser->driver->findElement(WebDriverBy::cssSelector('dialog[open] form')),
                    $enEntier($zoneVide['dx']),
                    $enEntier($zoneVide['dy'])
                )
                ->click()
                ->perform();

            $clic = $browser->script('return window.__cliqueDuTest;')[0];
            $this->assertIsArray($clic, 'Le clic de test n’a pas été reçu par le formulaire.');
            $this->assertTrue($clic['dansFormulaire'], 'Le clic doit tomber dans le formulaire de création.');

            $focus = $browser->script('return document.activeElement ? document.activeElement.tagName : null;')[0];
            $this->assertNotContains(
                $focus,
                ['INPUT', 'TEXTAREA', 'SELECT'],
                'Le focus doit être hors de tout champ avant Échap.'
            );

            // Premier Échap : dans le formulaire, il revient à la liste et le tiroir reste ouvert.
            $browser->driver->action()->sendKeys(null, WebDriverKeys::ESCAPE)->perform();

            $browser->waitUntilMissing('@new-exercise-name', 15)
                ->waitFor('@quick-create-exercise', 15)
                ->assertPresent('dialog[open]')
                ->assertSeeIn('#add-exercise-title', 'Ajouter un exercice');

            // Second Échap, dans la liste : le tiroir se ferme.
            $browser->driver->action()->sendKeys(null, WebDriverKeys::ESCAPE)->perform();

            $browser->waitUntilMissing('dialog[open]', 15)
                ->assertMissing('dialog[open]');
        });
    }
}
