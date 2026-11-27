<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * La page elle-même allume, dans la barre du bas, l'onglet où mène son bouton
 * Retour (#2434) : la barre et les retours lisent la même table
 * (`resources/js/Utils/ongletsDeLaBarre.js`). Sur le vrai navigateur et les
 * vraies routes, pas sur une liste de noms écrite à la main.
 */
class OngletActifDesPagesTest extends DuskTestCase
{
    use DatabaseTruncation;

    private const string LIT = <<<'JS'
        const retour = document.querySelector('header a[aria-label="Retour"]');
        const allumes = [...document.querySelectorAll('nav[aria-label="Navigation principale"] a[aria-current="page"]')];

        return {
            retour: retour === null ? null : new URL(retour.href).pathname,
            allumes: allumes.map((lien) => new URL(lien.href).pathname),
        };
        JS;

    public function test_chaque_page_allume_l_onglet_de_son_retour(): void
    {
        $compte = User::factory()->create([
            'email' => 'onglet-'.time().random_int(0, 999).'@example.com',
            'email_verified_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs(User::findOrFail($compte->id))->resizeToIphone15();

            foreach (['/goals', '/habits', '/daily-journals', '/supplements', '/achievements', '/corps', '/records'] as $adresse) {
                $browser->visit($adresse)->disableAnimations()->waitFor('#main-content', 30)->waitFor('nav[aria-label="Navigation principale"]', 30);

                /** @var array{retour: ?string, allumes: list<string>} $page */
                $page = $browser->script(self::LIT)[0];

                $this->assertNotNull($page['retour'], "$adresse n'a pas de bouton Retour.");
                $this->assertSame([$page['retour']], $page['allumes'], "$adresse n'allume pas l'onglet où mène son retour.");
            }

            // Le calendrier n'a plus de flèche sur téléphone : la rangée des séances la remplace (OngletsDesSeancesTest), et Séances reste allumé.
            $browser->visit('/calendar')->disableAnimations()->waitFor('#main-content', 30)->waitFor('nav[aria-label="Navigation principale"]', 30);
            /** @var array{retour: ?string, allumes: list<string>} $page */
            $page = $browser->script(self::LIT)[0];

            $this->assertNull($page['retour'], '/calendar garde sa flèche de retour sur téléphone.');
            $this->assertSame(['/workouts'], $page['allumes'], "/calendar n'allume pas Séances.");

            // Poids et mensurations reviennent à Corps, qui n'est pas un onglet : Progrès reste allumé.
            foreach (['/body-measurements', '/body-metrics'] as $adresse) {
                $browser->visit($adresse)->disableAnimations()->waitFor('#main-content', 30)->waitFor('nav[aria-label="Navigation principale"]', 30);

                /** @var array{retour: ?string, allumes: list<string>} $page */
                $page = $browser->script(self::LIT)[0];

                $this->assertSame('/corps', $page['retour'], "$adresse ne revient pas à Corps.");
                $this->assertSame(['/stats'], $page['allumes'], "$adresse n'allume pas Progrès.");
            }

            // La cloche mène aux notifications : leur retour va à l'Accueil, qui reste éteint.
            $browser->visit('/notifications')->disableAnimations()->waitFor('#main-content', 30)->waitFor('nav[aria-label="Navigation principale"]', 30);
            /** @var array{retour: ?string, allumes: list<string>} $page */
            $page = $browser->script(self::LIT)[0];

            $this->assertSame('/dashboard', $page['retour']);
            $this->assertSame([], $page['allumes']);
        });
    }
}
