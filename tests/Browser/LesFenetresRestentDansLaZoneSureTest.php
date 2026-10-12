<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Exception\WebDriverException;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Les fenêtres et les bandeaux restent dans la zone sûre de l'écran (#2043).
 *
 * Dans l'application installée sur iPhone, la page s'étend sous la barre
 * d'état et l'indicateur d'accueil (`viewport-fit=cover`). La fenêtre
 * « Ajouter un exercice » se posait à 24 px du haut de l'écran, sans compter la
 * barre d'état : son titre passait dessous. Sa liste, bornée à 60 % de la
 * hauteur de l'écran, laissait en même temps un grand vide sous la fenêtre.
 *
 * Chrome simule les marges d'un téléphone à encoche par ses outils de
 * développement (`Emulation.setSafeAreaInsetsOverride`) : `env(safe-area-inset-*)`
 * y prend les valeurs données, comme sur l'appareil. Le parcours mesure ce que
 * le navigateur dessine, pas les classes écrites.
 */
class LesFenetresRestentDansLaZoneSureTest extends DuskTestCase
{
    /**
     * Les marges que le navigateur applique vraiment, lues sur une sonde.
     */
    private const string LIRE_LES_MARGES = <<<'JS'
        const sonde = document.createElement('div');
        sonde.style.cssText = 'position: fixed; visibility: hidden; padding: env(safe-area-inset-top, 0px) env(safe-area-inset-right, 0px) env(safe-area-inset-bottom, 0px) env(safe-area-inset-left, 0px);';
        document.body.appendChild(sonde);
        const style = getComputedStyle(sonde);
        const marges = {
            haut: parseFloat(style.paddingTop),
            droite: parseFloat(style.paddingRight),
            bas: parseFloat(style.paddingBottom),
            gauche: parseFloat(style.paddingLeft),
        };
        sonde.remove();
        return marges;
        JS;

    /**
     * Où la fenêtre ouverte et son titre se dessinent.
     */
    private const string MESURER_LA_FENETRE = <<<'JS'
        const panneau = document.querySelector('dialog[open] .glass-modal').getBoundingClientRect();
        const titre = document.getElementById('add-exercise-title').getBoundingClientRect();
        return {
            panneauHaut: panneau.top,
            panneauBas: panneau.bottom,
            panneauGauche: panneau.left,
            panneauDroite: panneau.right,
            titreHaut: titre.top,
            hauteur: innerHeight,
            largeur: innerWidth,
        };
        JS;

    public function test_en_portrait_la_fenetre_d_ajout_d_exercice_tient_entre_la_barre_d_etat_et_l_indicateur_d_accueil(): void
    {
        [$compte, $seance] = $this->seanceAvecUneLongueBibliotheque();

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            $browser->loginAs($compte)->resizeToIphone15();

            $marges = $this->simulerLesMarges($browser, ['top' => 47, 'bottom' => 34, 'left' => 0, 'right' => 0]);

            $this->ouvrirLAjoutDExercice($browser, $seance);

            /** @var array{panneauHaut: float, panneauBas: float, titreHaut: float, hauteur: float} $fenetre */
            $fenetre = $browser->script(self::MESURER_LA_FENETRE)[0];

            $this->assertGreaterThanOrEqual(
                $marges['haut'],
                $fenetre['panneauHaut'],
                'La fenêtre commence sous la barre d’état, pas derrière elle.'
            );
            $this->assertGreaterThanOrEqual(
                $marges['haut'],
                $fenetre['titreHaut'],
                'Le titre « Ajouter un exercice » se lit sous la barre d’état.'
            );

            $basDeLaZoneSure = $fenetre['hauteur'] - $marges['bas'];

            $this->assertLessThanOrEqual(
                $basDeLaZoneSure,
                $fenetre['panneauBas'],
                'Le bas de la fenêtre reste au-dessus de l’indicateur d’accueil.'
            );

            /*
             * Une longue liste remplit la zone sûre : la fenêtre garde la même
             * marge au-dessus et au-dessous d'elle, au lieu d'un grand vide en bas.
             */
            $margeAuDessus = $fenetre['panneauHaut'] - $marges['haut'];
            $margeAuDessous = $basDeLaZoneSure - $fenetre['panneauBas'];

            $this->assertEqualsWithDelta(
                $margeAuDessus,
                $margeAuDessous,
                1.0,
                sprintf('La fenêtre laisse %.0f px au-dessus d’elle et %.0f px au-dessous.', $margeAuDessus, $margeAuDessous)
            );

            /** @var array{listeDefile: bool, fenetreDefile: bool} $defilement */
            $defilement = $browser->script(<<<'JS'
                const liste = document.querySelector('[dusk="liste-des-exercices"]');
                const panneau = document.querySelector('dialog[open] .glass-modal');
                return {
                    listeDefile: liste.scrollHeight > liste.clientHeight,
                    fenetreDefile: panneau.scrollHeight > panneau.clientHeight + 1,
                };
                JS)[0];

            $this->assertTrue($defilement['listeDefile'], 'La liste défile en elle-même.');
            $this->assertFalse($defilement['fenetreDefile'], 'Le titre et la recherche restent en place : seule la liste défile.');

            /** @var array{titreHaut: float, navigationBas: float} $cadre */
            $cadre = $browser->script(<<<'JS'
                return {
                    titreHaut: document.querySelector('header.z-collant h1').getBoundingClientRect().top,
                    navigationBas: document.querySelector('nav.glass-nav').getBoundingClientRect().bottom,
                };
                JS)[0];

            $this->assertGreaterThanOrEqual($marges['haut'], $cadre['titreHaut'], 'Le titre de la page se lit sous la barre d’état.');
            $this->assertLessThanOrEqual($basDeLaZoneSure, $cadre['navigationBas'], 'La barre de navigation reste au-dessus de l’indicateur d’accueil.');
        });
    }

    public function test_en_paysage_l_en_tete_et_le_contenu_s_ecartent_de_l_encoche(): void
    {
        [$compte, $seance] = $this->seanceAvecUneLongueBibliotheque();

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            $browser->loginAs($compte)->resize(844, 390);

            $marges = $this->simulerLesMarges($browser, ['top' => 0, 'bottom' => 21, 'left' => 47, 'right' => 47]);

            $this->ouvrirLAjoutDExercice($browser, $seance);

            /** @var array{panneauHaut: float, panneauBas: float, panneauGauche: float, panneauDroite: float, hauteur: float, largeur: float} $fenetre */
            $fenetre = $browser->script(self::MESURER_LA_FENETRE)[0];

            $this->assertGreaterThanOrEqual($marges['gauche'], $fenetre['panneauGauche'], 'La fenêtre s’écarte de l’encoche à gauche.');
            $this->assertLessThanOrEqual($fenetre['largeur'] - $marges['droite'], $fenetre['panneauDroite'], 'La fenêtre s’écarte du bord droit.');
            $this->assertLessThanOrEqual($fenetre['hauteur'] - $marges['bas'], $fenetre['panneauBas'], 'Le bas de la fenêtre reste au-dessus de l’indicateur d’accueil.');

            /*
             * En paysage, l'en-tête de bureau s'affiche (la largeur dépasse `sm`) :
             * son logo se posait à 24 px du bord, sous l'encoche, comme le
             * contenu de la page. Le bord droit de l'en-tête n'est pas mesuré :
             * à 844 px, ses liens débordent déjà de l'écran, encoche ou non.
             */
            /** @var array{logoGauche: float, contenuGauche: float, contenuDroite: float} $cadre */
            $cadre = $browser->script(<<<'JS'
                const contenu = document.querySelector('#main-content > div:last-child').getBoundingClientRect();
                return {
                    logoGauche: document.querySelector('nav.z-nav a').getBoundingClientRect().left,
                    contenuGauche: contenu.left,
                    contenuDroite: contenu.right,
                };
                JS)[0];

            $this->assertGreaterThanOrEqual($marges['gauche'], $cadre['logoGauche'], 'Le logo de l’en-tête s’écarte de l’encoche.');
            $this->assertGreaterThanOrEqual($marges['gauche'], $cadre['contenuGauche'], 'Le contenu de la page s’écarte de l’encoche.');
            $this->assertLessThanOrEqual($fenetre['largeur'] - $marges['droite'], $cadre['contenuDroite'], 'Le contenu de la page s’écarte du bord droit.');
        });
    }

    /**
     * Un compte, sa séance en cours, et assez d'exercices pour que la liste
     * dépasse la hauteur de l'écran.
     *
     * @return array{User, Workout}
     */
    private function seanceAvecUneLongueBibliotheque(): array
    {
        $compte = User::factory()->create(['email_verified_at' => now()]);
        $seance = Workout::factory()->for($compte)->create(['ended_at' => null]);

        Exercise::factory()
            ->count(30)
            ->state(new Sequence(static fn (Sequence $rang): array => ['name' => 'Exercice zone sûre '.($rang->index + 1)]))
            ->create(['user_id' => $compte->id]);

        return [$compte, $seance];
    }

    /**
     * Donne au navigateur les marges d'un téléphone à encoche, et vérifie
     * qu'elles s'appliquent avant de mesurer quoi que ce soit.
     *
     * @param  array{top: int, bottom: int, left: int, right: int}  $marges
     * @return array{haut: float, droite: float, bas: float, gauche: float}
     */
    private function simulerLesMarges(Browser $browser, array $marges): array
    {
        $outils = new ChromeDevToolsDriver($browser->driver);

        try {
            $outils->execute('Emulation.setSafeAreaInsetsOverride', ['insets' => $marges]);
        } catch (WebDriverException $exception) {
            $this->markTestSkipped('Ce navigateur ne sait pas simuler les marges de zone sûre : '.$exception->getMessage());
        }

        $browser->visit('/dashboard')->waitFor('#main-content', 30);

        /** @var array{haut: float, droite: float, bas: float, gauche: float} $appliquees */
        $appliquees = $browser->script(self::LIRE_LES_MARGES)[0];

        $lues = [
            'haut' => (float) $appliquees['haut'],
            'droite' => (float) $appliquees['droite'],
            'bas' => (float) $appliquees['bas'],
            'gauche' => (float) $appliquees['gauche'],
        ];

        $this->assertSame(
            ['haut' => (float) $marges['top'], 'droite' => (float) $marges['right'], 'bas' => (float) $marges['bottom'], 'gauche' => (float) $marges['left']],
            $lues,
            'Les marges simulées doivent s’appliquer à env(safe-area-inset-*), sinon le parcours ne mesure rien.'
        );

        return $lues;
    }

    private function ouvrirLAjoutDExercice(Browser $browser, Workout $seance): void
    {
        $browser->visit('/workouts/'.$seance->id)
            ->waitFor('#main-content', 30)
            ->disableAnimations()
            ->waitFor('@add-first-exercise', 15)
            ->script('document.querySelector(\'[dusk="add-first-exercise"]\').click();');

        $browser->waitFor('#add-exercise-title', 15)
            ->waitFor('[dusk^="select-exercise-"]', 15)
            ->waitForStableLayout();
    }
}
