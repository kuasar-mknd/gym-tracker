<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Facebook\WebDriver\Exception\TimeoutException;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ParcoursHorsLigne;

/**
 * Sans réseau, depuis la séance ouverte, les pages de salle s'ouvrent depuis
 * leurs copies, et un lien vers une autre page le dit (#2048, partie E).
 *
 * Avant, un appui sur Séances, Plus ou la flèche de l'en-tête ne faisait
 * rien (constat a de l'issue) : la visite d'Inertia partait au réseau, et
 * rien ne l'écoutait échouer. La page garde désormais des copies chiffrées
 * des pages de salle (partie D), et les sert à une visite qui quitte la page
 * sans réponse (`resources/js/Utils/visitesHorsLigne.js`), avec un bandeau
 * daté. Ce parcours le joue dans un vrai Chrome, sur le critère d'acceptation
 * de l'issue : l'accueil ouvert par un chargement complet sans rien toucher,
 * puis la séance en cours depuis lui ; les copies attendues dans IndexedDB,
 * comptées par script (le préchauffage écrit celles des pages que le parcours
 * n'ouvre pas) ; puis la coupure. Depuis la séance, Stats, qui n'a pas de
 * copie, le dit et laisse la séance ; la flèche de l'en-tête ouvre la copie
 * des séances, où la prop différée que le préchauffage ne demande jamais dit
 * « Indisponible hors ligne », et le graphique de l'historique, hors du
 * precache, « Graphique indisponible hors ligne » ; le bouton Retour du
 * navigateur rend la séance ;
 * Séances, Plus (où démarrer une séance reste ouvert depuis #2049), Outils et le minuteur
 * d'intervalles s'ouvrent ensuite avec le bandeau, puis de nouveau Séances.
 * Rien d'autre n'est dit. Le réseau rendu, la copie des séances se recharge
 * en entier sans être remontée (`router.reload` garde l'état), perd son
 * bandeau, et son graphique, que le composant asynchrone gardait en échec, se
 * dessine.
 *
 * Couper le réseau par les outils de Chrome (`Network.emulateNetworkConditions`)
 * ne coupe que la page : un `import()` que le worker relaie atteint encore le
 * serveur, qui servirait ce que le precache n'a pas (`.ai/rules/browser.md`).
 * Le parcours rend donc public/build introuvable pendant la coupure (renommé,
 * rétabli dans `finally`), et vide le cache HTTP du navigateur et les deux
 * caches du worker que la route des actifs lit après le precache
 * (`actifs-a-la-demande`, `construction-precedente`) : chaque morceau, chaque
 * feuille et chaque police d'une page ouverte pendant la coupure vient du
 * precache, ou la page ne s'ouvre pas (« Mise à jour à terminer avec le
 * réseau »).
 *
 * L'ouverture à froid sans réseau (partie F) est le second parcours : un
 * `visit()` pendant la coupure, que le worker sert par la coquille, ouvre
 * l'accueil puis la séance en cours depuis leurs copies, et la séance se
 * recharge au retour du réseau. Comme `CopiesHorsLigneTest`, les deux exigent
 * un contexte sûr : la CI sert 127.0.0.1 ; sous Sail, servi en http sur
 * `laravel.test`, ils se déclarent sautés. Ce qu'ils partagent avec les
 * autres parcours hors ligne (lire le magasin, couper le réseau et retirer la
 * construction) vit dans `Tests\Support\ParcoursHorsLigne`.
 */
class NavigationHorsLigneTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    private const string PAGE_INDISPONIBLE = "Cette page n'est pas disponible sans réseau.";

    /**
     * Si la carte « Aperçu Historique » de la liste des séances porte son
     * canevas, et plus le composant d'erreur des graphiques.
     */
    private const string LE_GRAPHIQUE_DE_L_HISTORIQUE_EST_DESSINE = <<<'SCRIPT'
        (() => {
            const titre = [...document.querySelectorAll('h3')].find((h3) => h3.textContent.trim() === 'Aperçu Historique');
            const carte = titre?.parentElement?.parentElement ?? null;

            return carte !== null && carte.querySelector('canvas') !== null && carte.querySelector('[data-graphique-indisponible]') === null;
        })()
    SCRIPT;

    public function test_sans_reseau_la_seance_mene_aux_pages_de_salle_par_leurs_copies(): void
    {
        $jetons = [
            'seance' => Str::random(12),
            'exercice' => Str::random(12),
        ];
        $compte = User::factory()->create([
            'email' => 'navigation-hors-ligne-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
        $seance = Workout::factory()->for($compte)->create([
            'name' => 'Seance '.$jetons['seance'],
            'started_at' => now()->subMinutes(20),
            'ended_at' => null,
        ]);
        $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Exercice '.$jetons['exercice']]);
        $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id, 'order' => 0]);
        Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 82.5, 'reps' => 7]);

        $cheminDeLaSeance = '/workouts/'.$seance->id;

        $this->browse(function (Browser $browser) use ($compte, $jetons, $cheminDeLaSeance): void {
            // L'accueil par un chargement complet, sans rien toucher.
            $browser->loginAs($compte)
                ->visit('/dashboard')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);

            // Installé, precache compris, et maître de la page.
            $this->attendreLeWorker($browser);

            // Puis la séance en cours, depuis lui.
            $browser->disableAnimations()
                ->waitFor('[dusk="active-workout-banner"]', 30)
                ->clickWhenSettled('[dusk="active-workout-banner"]')
                ->waitForLocation($cheminDeLaSeance, 15)
                ->waitForText($jetons['exercice'], 15, true);

            // Les copies de l'accueil et de la séance, que la page a reçues, et celles du préchauffage.
            $this->attendreLesCopies($browser, ['/dashboard', $cheminDeLaSeance, '/workouts', '/profile', '/tools', '/tools/interval-timer']);

            $this->noterLesMessages($browser);

            $outils = $this->outilsDuNavigateur($browser);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser, $jetons, $cheminDeLaSeance): void {
                // Stats n'a pas de copie : la séance reste, et le dit.
                $browser->clickWhenSettled('[dusk="nav-stats"]')
                    ->waitUntil($this->laPageDit(self::PAGE_INDISPONIBLE), 15, 'Stats n’a rien dit sans réseau')
                    ->assertPathIs($cheminDeLaSeance)
                    ->assertMissing('[dusk="bandeau-hors-ligne"]');

                // La flèche de l'en-tête, un lien d'Inertia vers les séances, ouvre leur copie.
                $browser->clickWhenSettled('header a[aria-label="Retour"]');
                $this->attendreLaCopie($browser, '/workouts', 'Mes Séances');
                /*
                 * La séance en cours est dans la copie. Le préchauffage ne demande jamais la prop différée
                 * de la liste, qui le dit ; le graphique de l'historique, hors du precache, aussi.
                 */
                $browser->waitForText($jetons['seance'], 15, true)
                    ->waitFor('[data-indisponible-hors-ligne]', 15)
                    ->waitFor('[data-graphique-indisponible]', 15);

                // Le bouton Retour du navigateur rend la séance telle que l'historique l'a gardée, sans le serveur.
                $browser->back()
                    ->waitForLocation($cheminDeLaSeance, 15)
                    ->waitForText($jetons['exercice'], 15, true)
                    ->assertMissing('[dusk="bandeau-hors-ligne"]');

                // Depuis la séance : Séances, Plus, Outils, puis le minuteur d'intervalles.
                $browser->clickWhenSettled('[dusk="nav-workouts"]');
                $this->attendreLaCopie($browser, '/workouts', 'Mes Séances');

                $browser->clickWhenSettled('[dusk="nav-profile"]');
                $this->attendreLaCopie($browser, '/profile', 'Plus');
                // Démarrer reste ouvert sur une copie (#2049) : rien n'y est tiré d'elle, et sans réseau la séance s'ouvre depuis la file.
                $browser->assertScript("(() => { const bouton = document.querySelector('button.glass-nav-fab'); return bouton !== null && !bouton.disabled && !bouton.hasAttribute('aria-describedby'); })()");

                $browser->clickWhenSettled('main a[href$="/tools"]');
                $this->attendreLaCopie($browser, '/tools', 'Outils');

                $browser->clickWhenSettled('main a[href$="/tools/interval-timer"]');
                $this->attendreLaCopie($browser, '/tools/interval-timer', 'Minuteur');

                // Puis les séances de nouveau, leur graphique indisponible : c'est la copie que le réseau retrouvera.
                $browser->clickWhenSettled('[dusk="nav-workouts"]');
                $this->attendreLaCopie($browser, '/workouts', 'Mes Séances');
                $browser->waitFor('[data-graphique-indisponible]', 15);

                // Rien d'autre n'a été dit : ni page indisponible, ni « Mise à jour à terminer avec le réseau ».
                $this->assertSame([self::PAGE_INDISPONIBLE], $this->messagesSansReseau($browser));
            });

            /*
             * Le réseau revenu, la copie affichée se recharge en entier, et le bandeau part avec sa marque.
             * La page n'est pas remontée : le graphique de l'historique, en échec sur la copie, se dessine
             * quand même, son morceau de nouveau servi.
             */
            $browser->waitUntilMissing('[dusk="bandeau-hors-ligne"]', 90)
                ->assertPathIs('/workouts')
                ->assertScript($this->texteDe('header h1'), 'Mes Séances')
                ->waitUntil(self::LE_GRAPHIQUE_DE_L_HISTORIQUE_EST_DESSINE, 30, 'le graphique de l’historique dit encore qu’il est indisponible hors ligne');
        });
    }

    /**
     * À froid, réseau coupé, l'accueil puis la séance en cours s'ouvrent
     * depuis leurs copies, sur la coquille (#2048, partie F).
     *
     * Le critère de l'issue : `visit('/dashboard')` puis
     * `visit('/workouts/{en cours}')`, deux navigations complètes pendant la
     * coupure. Le worker ne les sert pas : la requête préchargée échoue, et il
     * rend la page « hors ligne » précachée, marquée `data-raison="reseau"`.
     * C'est la coquille qui démarre l'application, sur la copie chiffrée de
     * l'adresse (`resources/js/Utils/demarrageHorsLigne.js`) : le bandeau la
     * date, l'entrée d'historique est chiffrée, et rien du contenu statique
     * (« Pas de réseau ») ne reste. public/build est introuvable pendant la
     * coupure : le module de l'entrée, le démarrage, les morceaux des deux
     * pages et leurs feuilles viennent du precache. Le réseau rendu, la séance
     * se recharge en entier (sa file est vide) et perd son bandeau.
     */
    public function test_sans_reseau_l_accueil_puis_la_seance_en_cours_s_ouvrent_a_froid_depuis_leurs_copies(): void
    {
        $jetons = [
            'seance' => Str::random(12),
            'exercice' => Str::random(12),
        ];
        $compte = User::factory()->create([
            'email' => 'ouverture-a-froid-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
        $seance = Workout::factory()->for($compte)->create([
            'name' => 'Seance '.$jetons['seance'],
            'started_at' => now()->subMinutes(20),
            'ended_at' => null,
        ]);
        $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Exercice '.$jetons['exercice'], 'type' => 'strength']);
        $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id, 'order' => 0]);
        Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 82.5, 'reps' => 7]);

        $cheminDeLaSeance = '/workouts/'.$seance->id;

        $this->browse(function (Browser $browser) use ($compte, $jetons, $cheminDeLaSeance): void {
            $browser->loginAs($compte)
                ->visit('/dashboard')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);

            $browser->disableAnimations()
                ->waitFor('[dusk="active-workout-banner"]', 30)
                ->clickWhenSettled('[dusk="active-workout-banner"]')
                ->waitForLocation($cheminDeLaSeance, 15)
                ->waitForText($jetons['exercice'], 15, true);

            $this->attendreLesCopies($browser, ['/dashboard', $cheminDeLaSeance]);

            $outils = $this->outilsDuNavigateur($browser);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser, $jetons, $cheminDeLaSeance): void {
                $browser->visit('/dashboard');
                $this->attendreLaCopieAFroid($browser, '/dashboard', 'Accueil');
                // La séance en cours, que l'accueil annonce, vient de la copie.
                $browser->waitFor('[dusk="active-workout-banner"]', 15)
                    ->assertScript($this->laPageDit($jetons['seance']));

                $browser->visit($cheminDeLaSeance);
                $this->attendreLaCopieAFroid($browser, $cheminDeLaSeance, 'Seance '.$jetons['seance']);
                $browser->waitForText($jetons['exercice'], 15, true)
                    ->assertValue('[dusk="weight-input-0-0"]', '82.5')
                    ->assertValue('[dusk="reps-input-0-0"]', '7');
            });

            // Le réseau rendu, la séance, dont la file est vide, se recharge en entier, et le bandeau part avec sa marque.
            $browser->waitUntilMissing('[dusk="bandeau-hors-ligne"]', 90)
                ->assertPathIs($cheminDeLaSeance)
                ->waitForText($jetons['exercice'], 15, true);
        });
    }

    /**
     * Attend la copie qu'une navigation complète a ouverte sur la coquille :
     * la page « hors ligne » servie sans réseau, l'application démarrée sur
     * la copie (son bandeau daté, son titre), rien du contenu statique, et
     * l'entrée d'historique chiffrée.
     */
    private function attendreLaCopieAFroid(Browser $browser, string $chemin, string $titre): void
    {
        $this->attendreLaCoquilleServieA($browser, $chemin)
            ->waitFor('[dusk="bandeau-hors-ligne"]', 15)
            ->assertPathIs($chemin)
            ->assertMissing('[dusk="page-hors-ligne"]')
            ->assertScript($this->texteDe('[dusk="bandeau-hors-ligne-titre"]').".startsWith('Hors ligne · copie du ')")
            ->assertScript($this->texteDe('header h1'), $titre)
            ->waitUntil('history.state?.page instanceof ArrayBuffer', 15, "l’entrée d’historique de la copie de {$chemin} n’est pas chiffrée");
    }

    /**
     * Attend la copie d'une page de salle, ouverte depuis la page précédente :
     * son adresse, son bandeau daté et le titre de son en-tête, lus dans le
     * DOM (`titre-carte` passe le texte en majuscules, que WebDriver rend), et
     * son entrée d'historique, chiffrée (`encryptHistory`, écrit en dur dans
     * la page servie).
     */
    private function attendreLaCopie(Browser $browser, string $chemin, string $titre): void
    {
        try {
            $browser->waitForLocation($chemin, 15);
        } catch (TimeoutException) {
            $this->fail("la copie de {$chemin} ne s’est pas ouverte ; la page a dit : ".implode(' | ', $this->messagesSansReseau($browser)));
        }

        $browser->waitFor('[dusk="bandeau-hors-ligne"]', 15)
            ->assertScript($this->texteDe('[dusk="bandeau-hors-ligne-titre"]').".startsWith('Hors ligne · copie du ')")
            ->assertScript($this->texteDe('header h1'), $titre)
            // L'entrée d'historique de la copie est chiffrée, comme celles des pages du serveur.
            ->waitUntil('history.state?.page instanceof ArrayBuffer', 15, "l’entrée d’historique de la copie de {$chemin} n’est pas chiffrée");
    }
}
