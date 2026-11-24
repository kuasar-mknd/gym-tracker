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

        const focalisables = [...rail.querySelectorAll('button')].filter(visible);
        const ordreVisuel = [...focalisables].sort((a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top);

        return {
            tabulationSuitLeVisuel: focalisables.every((bouton, i) => bouton === ordreVisuel[i]),
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
            foreach ([1920, 1280, 1100, 1024] as $largeur) {
                $browser->loginAs(User::findOrFail($compte->id))->resize($largeur, 800)
                    ->visit("/workouts/{$seance->id}")->disableAnimations()->waitFor('[dusk="barre-d-outils-de-la-seance"]', 30)->waitForStableLayout();
                $browser->script('window.scrollTo(0, document.documentElement.scrollHeight)');
                $browser->waitForStableLayout();

                /** @var array{position: string, largeurDuRail: int, railDansLEcran: bool, boutonsVisibles: list<bool>, largeurDeLaListe: int, tabulationSuitLeVisuel: bool, gaucheDuRail: int, droiteDeLaListe: int, debordement: int, chevauche: bool} $page */
                $page = $browser->script(self::LIT)[0];
                $ou = "à $largeur px";

                $this->assertSame('sticky', $page['position'], "Le rail n'est pas collant $ou.");
                $this->assertSame(288, $page['largeurDuRail'], "Le rail ne fait pas 288 px $ou.");
                $this->assertTrue($page['railDansLEcran'], "Le rail est sorti de l'écran $ou.");
                $this->assertSame([true, true, true, true], $page['boutonsVisibles'], "Un bouton du rail est hors de l'écran $ou.");
                $this->assertGreaterThanOrEqual([1024 => 633, 1100 => 709][$largeur] ?? 768, $page['largeurDeLaListe'], "La liste n'a que {$page['largeurDeLaListe']} px $ou.");
                $this->assertGreaterThan($page['droiteDeLaListe'], $page['gaucheDuRail'], "Le rail mord sur la liste $ou.");
                $this->assertLessThanOrEqual(0, $page['debordement'], "La page défile de côté $ou.");
                $this->assertTrue($page['tabulationSuitLeVisuel'], "La tabulation du rail ne suit pas l'ordre visuel $ou.");
                $this->assertFalse($page['chevauche'], "Deux champs se chevauchent $ou.");
            }
        });
    }

    public function test_le_rail_collant_ne_recouvre_ni_le_bandeau_de_mise_a_jour_ni_un_toast_et_tient_dans_600_px(): void
    {
        $compte = User::factory()->create(['email' => 'rail-bandeau-'.time().random_int(0, 999).'@example.com', 'email_verified_at' => now()]);
        $seance = $this->seanceLongue($compte);
        $manifeste = json_decode((string) file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, array{file: string}> $manifeste */
        $principal = '/build/'.$manifeste['resources/js/main.js']['file'];

        $this->browse(function (Browser $browser) use ($compte, $seance, $principal): void {
            foreach ([1280, 1024] as $largeur) {
                $browser->loginAs(User::findOrFail($compte->id))->resize($largeur, 600)
                    ->visit("/workouts/{$seance->id}")->disableAnimations()->waitFor('[dusk="barre-d-outils-de-la-seance"]', 30)->waitForStableLayout();

                // Le vrai bandeau : l'état que pose le worker, trouvé parmi les exports du module que la page a déjà chargé.
                $browser->script(str_replace('__MODULE__', $principal, <<<'JS'
                    import('__MODULE__').then(async (module) => {
                        for (const valeur of Object.values(module)) {
                            if (valeur !== null && typeof valeur === 'object' && valeur.__v_isRef && valeur.value === false) {
                                valeur.value = true;
                                for (let essai = 0; essai < 40 && document.querySelector('[dusk="bandeau-mise-a-jour"]') === null; essai++) {
                                    await new Promise((fin) => requestAnimationFrame(fin));
                                }
                                if (document.querySelector('[dusk="bandeau-mise-a-jour"]') !== null) { return; }
                                valeur.value = false;
                            }
                        }
                    });
                    JS));
                $browser->waitFor('@bandeau-mise-a-jour', 10);

                // Le vrai toast : l'évènement que le filet des visites hors ligne envoie.
                $browser->script("window.dispatchEvent(new CustomEvent('gym:sans-reseau', { detail: { message: 'Il faut le réseau pour démarrer une séance.', annuler: null } }));");
                $browser->waitFor('.z-toast[role="alert"]', 10)->waitForStableLayout();
                $browser->script('window.scrollTo(0, document.documentElement.scrollHeight)');
                $browser->waitForStableLayout();

                /** @var array{rail: list<float>, bandeau: list<float>, toast: list<float>, hauteurDuRail: float} $rects */
                $rects = $browser->script(<<<'JS'
                    const boite = (element) => { const r = element.getBoundingClientRect(); return [r.left, r.top, r.right, r.bottom]; };
                    const rail = document.querySelector('[dusk="barre-d-outils-de-la-seance"]').parentElement;
                    return {
                        rail: boite(rail),
                        bandeau: boite(document.querySelector('[dusk="bandeau-mise-a-jour"]')),
                        toast: boite(document.querySelector('.z-toast[role="alert"]')),
                        hauteurDuRail: rail.getBoundingClientRect().height,
                    };
                    JS)[0];

                $secants = static fn (array $a, array $b): bool => $a[0] < $b[2] && $b[0] < $a[2] && $a[1] < $b[3] && $b[1] < $a[3];

                $this->assertFalse($secants($rects['rail'], $rects['bandeau']), "Le rail recouvre le bandeau de mise à jour à $largeur px : ".json_encode($rects));
                $this->assertFalse($secants($rects['rail'], $rects['toast']), "Le rail recouvre un toast à $largeur px.");
                $this->assertLessThanOrEqual(600, $rects['rail'][3], "Le rail sort d'une fenêtre de 600 px de haut à $largeur px.");
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

    public function test_sous_1024_px_la_liste_garde_la_largeur_et_la_marge_de_la_colonne_moyenne(): void
    {
        $compte = User::factory()->create(['email' => 'rail-moyenne-'.time().random_int(0, 999).'@example.com', 'email_verified_at' => now()]);
        $longue = $this->seanceLongue($compte);
        $vide = Workout::factory()->for($compte)->create(['started_at' => now()->subMinutes(2), 'ended_at' => null]);

        $this->browse(function (Browser $browser) use ($compte, $longue, $vide): void {
            foreach ([900, 1023] as $largeur) {
                foreach ([$longue, $vide] as $seance) {
                    $browser->loginAs(User::findOrFail($compte->id))->resize($largeur, 800)
                        ->visit("/workouts/{$seance->id}")->disableAnimations()->waitFor('[dusk="exercise-list"]', 30)->waitForStableLayout();

                    /** @var array{gauche: float, largeur: float, fenetre: int} $liste */
                    $liste = $browser->script(<<<'JS'
                        const boite = document.querySelector('[dusk="exercise-list"]').getBoundingClientRect();
                        return { gauche: boite.left, largeur: boite.width, fenetre: document.documentElement.clientWidth };
                        JS)[0];

                    $ou = "à $largeur px (séance {$seance->id})";
                    $this->assertEqualsWithDelta(768.0, $liste['largeur'], 1.0, "La liste ne fait pas 768 px $ou.");
                    $this->assertEqualsWithDelta(($liste['fenetre'] - 768) / 2, $liste['gauche'], 1.0, "La liste n'est pas centrée comme la colonne moyenne $ou.");
                }
            }
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

            $browser->clickWhenSettled('[dusk="open-rest-timer"]')->waitFor('@rest-timer', 10)->waitForStableLayout();

            $dansLeRail = $browser->script(<<<'JS'
                const rail = document.querySelector('[dusk="barre-d-outils-de-la-seance"]').parentElement;
                const minuteur = document.querySelector('[dusk="rest-timer"]');
                const haut = (nom) => document.querySelector(`[dusk="${nom}"]`).getBoundingClientRect().top;
                return rail.contains(minuteur) && document.querySelector('[dusk="open-rest-timer"]') === null
                    && haut('add-exercise-existing') < minuteur.getBoundingClientRect().top && minuteur.getBoundingClientRect().top < haut('finish-workout-mobile');
                JS)[0];

            $this->assertTrue($dansLeRail, 'Le minuteur ne remplace pas le bouton de repos dans le rail, entre Ajouter et Terminer.');
        });
    }
}
