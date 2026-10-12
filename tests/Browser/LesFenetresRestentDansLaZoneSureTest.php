<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\DailyJournal;
use App\Models\Exercise;
use App\Models\Habit;
use App\Models\User;
use App\Models\Workout;
use Carbon\CarbonImmutable;
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

            /*
             * La fenêtre ne glisse pas non plus dans son cadre. Une marge sous
             * le panneau, en plus de celle du cadre, faisait déborder celui-ci
             * de 24 px : au bout de la liste, toute la fenêtre remontait contre
             * la barre d'état.
             */
            /** @var array{cadreDeborde: bool, panneauHautApresDefilement: float} $cadreDeLaFenetre */
            $cadreDeLaFenetre = $browser->script(<<<'JS'
                const cadre = document.querySelector('dialog[open] [scroll-region]');
                const debordement = cadre.scrollHeight > cadre.clientHeight;
                cadre.scrollTop = cadre.scrollHeight;
                return {
                    cadreDeborde: debordement,
                    panneauHautApresDefilement: document.querySelector('dialog[open] .glass-modal').getBoundingClientRect().top,
                };
                JS)[0];

            $this->assertFalse($cadreDeLaFenetre['cadreDeborde'], 'Le cadre de la fenêtre ne déborde pas de l’écran.');
            $this->assertEqualsWithDelta(
                $fenetre['panneauHaut'],
                $cadreDeLaFenetre['panneauHautApresDefilement'],
                0.5,
                'La fenêtre reste où elle est quand on fait défiler son cadre.'
            );

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

            /*
             * Le formulaire de création dépasse la hauteur permise en paysage. Il
             * ne se plie pas comme la liste : la fenêtre défile, et son bouton
             * garde au bas la marge de la fenêtre. Plié, il débordait dans cette
             * marge et l'arrondi du panneau rognait le bouton.
             */
            $browser->type('#search-workout-exercise', 'Exercice absent de la bibliothèque')
                ->waitFor('@quick-create-exercise', 15)
                ->script('document.querySelector(\'[dusk="quick-create-exercise"]\').click();');

            $browser->waitFor('@new-exercise-name', 15)->waitForStableLayout();

            /** @var array{fenetreDefile: bool, margeSousLeBouton: float} $creation */
            $creation = $browser->script(<<<'JS'
                const panneau = document.querySelector('dialog[open] .glass-modal');
                panneau.scrollTop = panneau.scrollHeight;
                return {
                    fenetreDefile: panneau.scrollHeight > panneau.clientHeight,
                    margeSousLeBouton: panneau.getBoundingClientRect().bottom
                        - panneau.querySelector('button[type="submit"]').getBoundingClientRect().bottom,
                };
                JS)[0];

            $this->assertTrue($creation['fenetreDefile'], 'En paysage, le formulaire de création fait défiler la fenêtre.');
            $this->assertGreaterThanOrEqual(
                24.0,
                $creation['margeSousLeBouton'],
                sprintf('Le bouton « Créer » garde la marge du bas de la fenêtre (%.0f px).', $creation['margeSousLeBouton'])
            );
        });
    }

    public function test_ce_qui_colle_sous_l_en_tete_s_y_pose_sans_jour_ni_chevauchement(): void
    {
        $compte = User::factory()->create(['email_verified_at' => now()]);

        DailyJournal::factory()
            ->count(12)
            ->for($compte)
            ->state(new Sequence(static fn (Sequence $rang): array => ['date' => CarbonImmutable::parse('2026-03-01')->addDays($rang->index)->toDateString()]))
            ->create();
        Habit::factory()->count(12)->for($compte)->create();

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)->resizeToIphone15();

            $this->simulerLesMarges($browser, ['top' => 47, 'bottom' => 34, 'left' => 0, 'right' => 0]);
            $this->verifierCeQuiColleSousLEnTete($browser, 'en portrait');

            /*
             * En paysage, la largeur dépasse `sm` : c'est la barre de bureau qui
             * sert d'en-tête, plus basse que l'en-tête mobile.
             */
            $browser->resize(844, 390);

            $this->simulerLesMarges($browser, ['top' => 0, 'bottom' => 21, 'left' => 47, 'right' => 47]);
            $this->verifierCeQuiColleSousLEnTete($browser, 'en paysage');
        });
    }

    /**
     * Le titre d'un mois du journal et la ligne des jours des habitudes, une
     * fois collés, commencent au bas de l'en-tête : ni le contenu qui défile
     * ne se voit entre les deux, ni l'élément ne mord sur l'en-tête.
     */
    private function verifierCeQuiColleSousLEnTete(Browser $browser, string $orientation): void
    {
        foreach (['/daily-journals' => '@titre-du-mois', '/habits' => '@jours-des-habitudes'] as $chemin => $collant) {
            $browser->visit($chemin)
                ->waitFor('#main-content', 30)
                ->disableAnimations()
                ->waitFor($collant, 15)
                ->waitForStableLayout();

            /** @var array{colle: bool, hautDuCollant: float, basDeLEntete: float} $mesure */
            $mesure = $browser->script(sprintf(<<<'JS'
                const collant = document.querySelector('[dusk="%s"]');
                window.scrollTo({ top: collant.getBoundingClientRect().top + window.scrollY + 120, behavior: 'instant' });
                const entete = [...document.querySelectorAll('header.z-collant, nav.z-nav')]
                    .find((element) => getComputedStyle(element).display !== 'none');
                const haut = collant.getBoundingClientRect().top;
                return {
                    colle: Math.abs(haut - parseFloat(getComputedStyle(collant).top)) < 0.5,
                    hautDuCollant: haut,
                    basDeLEntete: entete.getBoundingClientRect().bottom,
                };
                JS, substr($collant, 1)))[0];

            $this->assertTrue($mesure['colle'], sprintf('%s %s : l’élément est collé une fois la page défilée.', $chemin, $orientation));
            $this->assertEqualsWithDelta(
                $mesure['basDeLEntete'],
                $mesure['hautDuCollant'],
                0.5,
                sprintf(
                    '%s %s : collé à %.0f px pour un en-tête qui finit à %.0f px.',
                    $chemin,
                    $orientation,
                    $mesure['hautDuCollant'],
                    $mesure['basDeLEntete']
                )
            );
        }
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
