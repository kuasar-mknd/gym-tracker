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

/**
 * Sur bureau, la séance en cours range ses actions dans un rail collant de
 * 288 px à droite de la liste (#2434) : visible sans défiler après le bas
 * d'une longue séance, sans rien ôter à la liste. Sous 1 024 px, la barre
 * flottante reste où elle était.
 */
class RailDeSeanceDeBureauTest extends DuskTestCase
{
    use DatabaseTruncation;

    private const string LIT = <<<'JS'
        const visible = (element) => element !== null && element.getClientRects().length > 0;
        const barre = document.querySelector('[dusk="barre-d-outils-de-la-seance"]');
        const rail = barre.parentElement;
        const dansLEcran = (element) => {
            const boite = element.getBoundingClientRect();
            return boite.top >= 0 && boite.bottom <= window.innerHeight;
        };
        const boutons = ['add-exercise-existing', 'open-rest-timer', 'finish-workout-mobile', 'enregistrer-comme-modele']
            .map((nom) => document.querySelector(`[dusk="${nom}"]`));
        const carte = document.querySelector('[dusk="exercise-list"] > *');
        const champs = [...document.querySelectorAll('[dusk="exercise-list"] input')].filter(visible).map((champ) => champ.getBoundingClientRect());
        const chevauche = champs.some((a, i) => champs.slice(i + 1).some((b) => a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1));

        return {
            position: getComputedStyle(rail).position,
            positionDeLaBarre: getComputedStyle(barre).position,
            largeurDuRail: Math.round(rail.getBoundingClientRect().width),
            railDansLEcran: visible(rail) && dansLEcran(rail),
            boutonsVisibles: boutons.map((bouton) => visible(bouton) && dansLEcran(bouton)),
            largeurDeLaListe: Math.round(carte.getBoundingClientRect().width),
            gaucheDuRail: Math.round(rail.getBoundingClientRect().left),
            droiteDeLaListe: Math.round(carte.getBoundingClientRect().right),
            debordement: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            chevauche,
        };
        JS;

    private function seanceLongue(User $compte): Workout
    {
        $seance = Workout::factory()->for($compte)->create(['name' => 'Longue', 'started_at' => now()->subMinutes(5), 'ended_at' => null]);

        foreach (range(0, 7) as $rang) {
            $ligne = WorkoutLine::factory()->create([
                'workout_id' => $seance->id,
                'exercise_id' => Exercise::factory()->create(['user_id' => $compte->id, 'type' => 'strength'])->id,
                'order' => $rang,
            ]);
            Set::factory()->count(3)->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 10]);
        }

        return $seance;
    }

    public function test_le_rail_reste_a_l_ecran_en_bas_d_une_longue_seance_sur_bureau(): void
    {
        $compte = User::factory()->create(['email' => 'rail-'.time().random_int(0, 999).'@example.com', 'email_verified_at' => now()]);
        $seance = $this->seanceLongue($compte);

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            foreach ([1920, 1280, 1024] as $largeur) {
                $browser->loginAs(User::findOrFail($compte->id))->resize($largeur, 800)
                    ->visit("/workouts/{$seance->id}")->disableAnimations()->waitFor('[dusk="barre-d-outils-de-la-seance"]', 30)->waitForStableLayout();
                $browser->script('document.documentElement.style.scrollbarWidth = "none"; window.scrollTo(0, document.documentElement.scrollHeight)');
                $browser->waitForStableLayout();

                /** @var array{position: string, largeurDuRail: int, railDansLEcran: bool, boutonsVisibles: list<bool>, largeurDeLaListe: int, gaucheDuRail: int, droiteDeLaListe: int, debordement: int, chevauche: bool} $page */
                $page = $browser->script(self::LIT)[0];
                $ou = "à $largeur px";

                $this->assertSame('sticky', $page['position'], "Le rail n'est pas collant $ou.");
                $this->assertSame(288, $page['largeurDuRail'], "Le rail ne fait pas 288 px $ou.");
                $this->assertTrue($page['railDansLEcran'], "Le rail est sorti de l'écran $ou.");
                $this->assertSame([true, true, true, true], $page['boutonsVisibles'], "Un bouton du rail est hors de l'écran $ou.");
                $this->assertGreaterThanOrEqual(640, $page['largeurDeLaListe'], "La liste n'a que {$page['largeurDeLaListe']} px $ou.");
                $this->assertGreaterThan($page['droiteDeLaListe'], $page['gaucheDuRail'], "Le rail mord sur la liste $ou.");
                $this->assertLessThanOrEqual(0, $page['debordement'], "La page défile de côté $ou.");
                $this->assertFalse($page['chevauche'], "Deux champs se chevauchent $ou.");
            }
        });
    }

    public function test_sous_1024_px_la_barre_flotte_et_il_n_y_a_pas_de_rail(): void
    {
        $compte = User::factory()->create(['email' => 'rail-bas-'.time().random_int(0, 999).'@example.com', 'email_verified_at' => now()]);
        $seance = $this->seanceLongue($compte);

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            $browser->loginAs(User::findOrFail($compte->id))->resize(1023, 800)
                ->visit("/workouts/{$seance->id}")->disableAnimations()->waitFor('[dusk="barre-d-outils-de-la-seance"]', 30)->waitForStableLayout();

            /** @var array{position: string, positionDeLaBarre: string, boutonsVisibles: list<bool>, debordement: int} $page */
            $page = $browser->script(self::LIT)[0];

            $this->assertSame('fixed', $page['positionDeLaBarre'], 'La barre ne flotte plus à 1 023 px.');
            $this->assertNotSame('sticky', $page['position'], 'Un rail collant existe à 1 023 px.');
            $this->assertLessThanOrEqual(0, $page['debordement'], 'La page défile de côté à 1 023 px.');
            $this->assertTrue($page['boutonsVisibles'][0] && $page['boutonsVisibles'][1] && $page['boutonsVisibles'][2], 'Les boutons de la barre sont absents.');
        });
    }

    public function test_une_seance_vide_n_a_qu_un_seul_ajouter_un_exercice_sur_bureau(): void
    {
        $compte = User::factory()->create(['email' => 'rail-vide-'.time().random_int(0, 999).'@example.com', 'email_verified_at' => now()]);
        $seance = Workout::factory()->for($compte)->create(['started_at' => now()->subMinutes(5), 'ended_at' => null]);

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            $browser->loginAs(User::findOrFail($compte->id))->resize(1280, 800)
                ->visit("/workouts/{$seance->id}")->disableAnimations()->waitFor('[dusk="barre-d-outils-de-la-seance"]', 30)->waitForStableLayout();

            /** @var int $ajouts */
            $ajouts = $browser->script(<<<'JS'
                return [...document.querySelectorAll('button')]
                    .filter((bouton) => bouton.getClientRects().length > 0 && /Ajouter un exercice/.test(bouton.textContent))
                    .length;
                JS)[0];

            $this->assertSame(1, $ajouts, "La séance vide montre $ajouts boutons « Ajouter un exercice ».");
            $browser->assertVisible('@abandonner-la-seance')->assertMissing('@enregistrer-comme-modele')->assertMissing('@open-rest-timer');
        });
    }

    public function test_une_seance_terminee_n_a_pas_de_rail_et_garde_sa_colonne_centree(): void
    {
        $compte = User::factory()->create(['email' => 'rail-fin-'.time().random_int(0, 999).'@example.com', 'email_verified_at' => now()]);
        $seance = $this->seanceLongue($compte);
        $seance->update(['ended_at' => now()]);

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            $browser->loginAs(User::findOrFail($compte->id))->resize(1280, 800)
                ->visit("/workouts/{$seance->id}")->disableAnimations()->waitFor('[dusk="exercise-list"]', 30)->waitForStableLayout();

            $browser->assertMissing('@barre-d-outils-de-la-seance');

            /** @var array{gauche: float, droite: float, largeur: float, fenetre: int} $liste */
            $liste = $browser->script(<<<'JS'
                const boite = document.querySelector('[dusk="exercise-list"]').getBoundingClientRect();
                return { gauche: boite.left, droite: boite.right, largeur: boite.width, fenetre: document.documentElement.clientWidth };
                JS)[0];

            $this->assertEqualsWithDelta(768.0, $liste['largeur'], 1.0, 'La liste terminée ne fait pas 768 px.');
            $this->assertEqualsWithDelta($liste['gauche'], $liste['fenetre'] - $liste['droite'], 1.0, 'La liste terminée n\'est pas centrée.');
        });
    }

    public function test_le_repos_qui_court_prend_la_place_du_bouton_dans_le_rail(): void
    {
        $compte = User::factory()->create(['email' => 'rail-repos-'.time().random_int(0, 999).'@example.com', 'email_verified_at' => now()]);
        $seance = $this->seanceLongue($compte);

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            $browser->loginAs(User::findOrFail($compte->id))->resize(1280, 800)
                ->visit("/workouts/{$seance->id}")->disableAnimations()->waitFor('@open-rest-timer', 30)->waitForStableLayout();

            $browser->click('@open-rest-timer')->waitFor('@rest-timer', 10)->waitForStableLayout();

            $dansLeRail = $browser->script(<<<'JS'
                const rail = document.querySelector('[dusk="barre-d-outils-de-la-seance"]').parentElement;
                const minuteur = document.querySelector('[dusk="rest-timer"]');
                return rail.contains(minuteur) && document.querySelector('[dusk="open-rest-timer"]') === null
                    && minuteur.getBoundingClientRect().top < document.querySelector('[dusk="add-exercise-existing"]').getBoundingClientRect().top;
                JS)[0];

            $this->assertTrue($dansLeRail, 'Le minuteur ne prend pas la tête du rail, à la place du bouton de repos.');
        });
    }
}
