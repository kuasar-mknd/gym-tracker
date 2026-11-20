<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Sur grand écran, la barre du haut porte les quatre onglets de la barre du bas
 * (lot 8, #2434), dans le même ordre, et allume celui de la page : les deux
 * barres lisent la même table (`resources/js/Utils/ongletsDeLaBarre.js`).
 * Sur le vrai navigateur et les vraies routes, aux trois largeurs de bureau.
 */
class BarreDeBureauTest extends DuskTestCase
{
    use DatabaseTruncation;

    private const string LIT = <<<'JS'
        const visible = (element) => element.getClientRects().length > 0;
        const onglets = [...document.querySelectorAll('[dusk="onglets-de-bureau"] a')];
        const titres = [...document.querySelectorAll('h1')].filter((titre) => visible(titre) && !titre.classList.contains('sr-only'));
        const retour = document.querySelector('[dusk="retour-de-bureau"]');

        return {
            onglets: onglets.map((lien) => lien.textContent.trim()),
            allumes: onglets.filter((lien) => lien.getAttribute('aria-current') === 'page').map((lien) => lien.textContent.trim()),
            titres: titres.map((titre) => titre.textContent.trim()),
            retour: retour !== null && visible(retour) ? new URL(retour.href).pathname : null,
            debordement: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
        JS;

    public function test_les_quatre_onglets_de_bureau_allument_celui_de_la_page(): void
    {
        $compte = User::factory()->create([
            'email' => 'bureau-'.time().random_int(0, 999).'@example.com',
            'email_verified_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($compte): void {
            foreach ([1024, 1280, 1536] as $largeur) {
                $browser->loginAs(User::findOrFail($compte->id))->resize($largeur, 900);

                // Adresse, onglet allumé, retour attendu (null : une page racine).
                $pages = [
                    '/dashboard' => ['Accueil', null],
                    '/workouts' => ['Séances', null],
                    '/calendar' => ['Séances', '/workouts'],
                    '/stats' => ['Progrès', null],
                    '/corps' => ['Progrès', '/stats'],
                    '/records' => ['Progrès', '/stats'],
                    '/body-measurements' => ['Progrès', '/corps'],
                    '/goals' => ['Progrès', '/stats'],
                    '/profile' => ['Profil', null],
                    // Outils pose son retour lui-même dans son en-tête : le layout n'ajoute pas le sien.
                    '/tools' => ['Profil', null],
                    '/habits' => ['Profil', '/profile'],
                ];

                foreach ($pages as $adresse => [$onglet, $retour]) {
                    $browser->visit($adresse)->disableAnimations()->waitFor('#main-content', 30)->waitFor('nav[aria-label="Navigation de bureau"]', 30);

                    /** @var array{onglets: list<string>, allumes: list<string>, titres: list<string>, retour: ?string, debordement: int} $page */
                    $page = $browser->script(self::LIT)[0];
                    $ou = "$adresse à $largeur px";

                    $this->assertSame(['Accueil', 'Séances', 'Progrès', 'Profil'], $page['onglets'], "$ou : les onglets ne sont pas ceux de la barre du bas.");
                    $this->assertSame([$onglet], $page['allumes'], "$ou n'allume pas $onglet.");
                    $this->assertSame($retour, $page['retour'], "$ou : le retour de bureau est faux.");
                    $this->assertLessThanOrEqual(1, count($page['titres']), "$ou montre plusieurs titres : ".implode(' | ', $page['titres']));
                    $this->assertLessThanOrEqual(0, $page['debordement'], "$ou défile de côté.");
                }
            }
        });
    }

    public function test_la_seance_porte_son_nom_et_ses_reglages_sur_bureau(): void
    {
        $compte = User::factory()->create([
            'email' => 'bureau-seance-'.time().random_int(0, 999).'@example.com',
            'email_verified_at' => now(),
        ]);
        $seance = Workout::factory()->for($compte)->create(['name' => 'Pousser', 'started_at' => now()->subMinutes(5), 'ended_at' => null]);
        WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => Exercise::factory()->create()->id, 'order' => 0]);

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            $browser->loginAs(User::findOrFail($compte->id))->resize(1280, 900)
                ->visit("/workouts/{$seance->id}")->disableAnimations()->waitFor('#main-content', 30);

            $browser->assertVisible('@workout-settings-button-desktop')
                ->assertScript('[...document.querySelectorAll("h1")].filter((titre) => titre.getClientRects().length > 0 && !titre.classList.contains("sr-only")).map((titre) => titre.textContent.trim()).join("|")', 'Pousser')
                ->assertPresent('@retour-de-bureau');

            // L'accessoire de séance, ici absent sur la page de la séance elle-même, tient sur bureau dans sa mesure.
            $browser->visit('/dashboard')->disableAnimations()->waitFor('#main-content', 30)->waitFor('[dusk="active-workout-banner"]', 30);

            /** @var float $largeur */
            $largeur = $browser->script('return document.querySelector(\'[dusk="active-workout-banner"]\').getBoundingClientRect().width')[0];

            $this->assertLessThanOrEqual(576, $largeur, "L'accessoire de séance s'étire sur {$largeur} px à 1280.");
        });
    }
}
