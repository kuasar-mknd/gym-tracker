<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Exception\TimeoutException;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

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
 * « Indisponible hors ligne » ; le bouton Retour du navigateur rend la séance ;
 * Séances, Plus (où démarrer une séance se désactive), Outils et le minuteur
 * d'intervalles s'ouvrent ensuite avec le bandeau. Rien d'autre n'est dit.
 * Le réseau rendu, la copie affichée se recharge en entier et perd son
 * bandeau.
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
 * L'ouverture à froid sans réseau (`visit()` pendant la coupure, que le
 * worker sert par la coquille) est la partie F. Comme `CopiesHorsLigneTest`,
 * le parcours exige un contexte sûr : la CI sert 127.0.0.1 ; sous Sail, servi
 * en http sur `laravel.test`, il se déclare sauté.
 */
class NavigationHorsLigneTest extends DuskTestCase
{
    /**
     * Les copies que le magasin garde, lues par script : leur chemin, leur
     * composant et la date de leur dernière réponse complète, rien de chiffré.
     * Une base absente ne se crée pas ici.
     */
    private const string LIRE_LES_COPIES = <<<'SCRIPT'
        const fini = arguments[arguments.length - 1];
        const ouverture = indexedDB.open('gym-tracker-copies');
        ouverture.onupgradeneeded = () => ouverture.transaction.abort();
        ouverture.onerror = () => fini([]);
        ouverture.onsuccess = () => {
            const base = ouverture.result;
            const demande = base.transaction('pages', 'readonly').objectStore('pages').getAll();
            demande.onsuccess = () => {
                base.close();
                fini(demande.result.map((copie) => ({ chemin: copie.chemin, composant: copie.composant })));
            };
            demande.onerror = () => {
                base.close();
                fini([]);
            };
        };
    SCRIPT;

    /**
     * Vide les caches du worker que la route des actifs lit après le precache :
     * il ne reste au navigateur que ce que le worker a précaché à l'installation.
     */
    private const string VIDER_LES_CACHES_DES_ACTIFS = <<<'SCRIPT'
        const fini = arguments[arguments.length - 1];
        Promise.all(['actifs-a-la-demande', 'construction-precedente'].map((nom) => caches.delete(nom))).then(
            () => fini(true),
            (erreur) => fini(String(erreur)),
        );
    SCRIPT;

    /**
     * Note chaque message que l'application annonce faute de réseau : les
     * toasts des layouts les montrent (`gym:sans-reseau`), puis s'effacent
     * d'eux-mêmes. Un échec dit ainsi ce que la page a répondu, et la fin du
     * parcours, qu'elle n'a rien dit d'autre.
     */
    private const string NOTER_LES_MESSAGES = <<<'SCRIPT'
        window.messagesSansReseau = [];
        window.addEventListener('gym:sans-reseau', (evenement) => {
            if (typeof evenement.detail?.message === 'string') {
                window.messagesSansReseau.push(evenement.detail.message);
            }
        });
    SCRIPT;

    private const string PAGE_INDISPONIBLE = "Cette page n'est pas disponible sans réseau.";

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

            if ($browser->script("return window.isSecureContext === true && 'serviceWorker' in navigator && 'indexedDB' in window && !!window.crypto?.subtle;")[0] !== true) {
                $this->markTestSkipped('Origine en http hors boucle locale : ni worker ni historique chiffré, et rien n’est gardé.');
            }

            // Installé, precache compris, et maître de la page.
            $browser->waitUntil(
                "navigator.serviceWorker.controller !== null && navigator.serviceWorker.controller.state === 'activated'",
                30,
                'le service worker n’a jamais pris la page en charge',
            );

            // Puis la séance en cours, depuis lui.
            $browser->disableAnimations()
                ->waitFor('[dusk="active-workout-banner"]', 30)
                ->clickWhenSettled('[dusk="active-workout-banner"]')
                ->waitForLocation($cheminDeLaSeance, 15)
                ->waitForText($jetons['exercice'], 15, true);

            // Les copies de l'accueil et de la séance, que la page a reçues, et celles du préchauffage.
            $this->attendreLesCopies($browser, ['/dashboard', $cheminDeLaSeance, '/workouts', '/profile', '/tools', '/tools/interval-timer']);

            $this->assertTrue(
                $browser->driver->executeAsyncScript(self::VIDER_LES_CACHES_DES_ACTIFS),
                'les caches des actifs du worker ne se sont pas vidés',
            );

            $browser->script(self::NOTER_LES_MESSAGES);

            $outils = new ChromeDevToolsDriver($browser->driver);
            $outils->execute('Network.enable');
            $outils->execute('Network.clearBrowserCache');

            $construction = public_path('build');
            $constructionRetiree = $construction.'.retiree';
            $this->assertDirectoryExists($construction, 'la construction doit exister');
            $this->assertDirectoryDoesNotExist($constructionRetiree, 'une construction retirée d’une passe précédente reste');

            $retiree = false;

            try {
                $this->couperLeReseau($outils, true);
                $retiree = rename($construction, $constructionRetiree);
                $this->assertTrue($retiree, 'la construction ne s’est pas retirée');

                // Stats n'a pas de copie : la séance reste, et le dit.
                $browser->clickWhenSettled('[dusk="nav-stats"]')
                    ->waitUntil($this->laPageDit(self::PAGE_INDISPONIBLE), 15, 'Stats n’a rien dit sans réseau')
                    ->assertPathIs($cheminDeLaSeance)
                    ->assertMissing('[dusk="bandeau-hors-ligne"]');

                // La flèche de l'en-tête, un lien d'Inertia vers les séances, ouvre leur copie.
                $browser->clickWhenSettled('header a[aria-label="Retour"]');
                $this->attendreLaCopie($browser, '/workouts', 'Mes Séances');
                // La séance en cours est dans la copie ; le préchauffage ne demande jamais la prop différée de la liste, qui le dit.
                $browser->waitForText($jetons['seance'], 15, true)
                    ->waitFor('[data-indisponible-hors-ligne]', 15);

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
                // Démarrer une séance ne partirait pas d'une copie : le bouton central se désactive, et dit pourquoi.
                $browser->assertScript("(() => { const bouton = document.querySelector('button.glass-nav-fab'); const raison = document.getElementById(bouton.getAttribute('aria-describedby') ?? ''); return bouton.disabled && raison !== null && raison.textContent.trim() !== ''; })()");

                $browser->clickWhenSettled('main a[href$="/tools"]');
                $this->attendreLaCopie($browser, '/tools', 'Outils');

                $browser->clickWhenSettled('main a[href$="/tools/interval-timer"]');
                $this->attendreLaCopie($browser, '/tools/interval-timer', 'Minuteur');

                // Rien d'autre n'a été dit : ni page indisponible, ni « Mise à jour à terminer avec le réseau ».
                $this->assertSame([self::PAGE_INDISPONIBLE], $this->messagesSansReseau($browser));
            } finally {
                if ($retiree) {
                    rename($constructionRetiree, $construction);
                }

                $this->couperLeReseau($outils, false);
            }

            // Le réseau revenu, la copie affichée se recharge en entier, et le bandeau part avec sa marque.
            $browser->waitUntilMissing('[dusk="bandeau-hors-ligne"]', 90)
                ->assertPathIs('/tools/interval-timer')
                ->assertScript($this->texteDe('header h1'), 'Minuteur');
        });
    }

    /**
     * Attend la copie d'une page de salle, ouverte depuis la page précédente :
     * son adresse, son bandeau daté et le titre de son en-tête, lus dans le
     * DOM (`titre-carte` passe le texte en majuscules, que WebDriver rend).
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
            ->assertScript($this->texteDe('header h1'), $titre);
    }

    /**
     * Les messages annoncés faute de réseau depuis la coupure, dans l'ordre.
     *
     * @return list<string>
     */
    private function messagesSansReseau(Browser $browser): array
    {
        /** @var list<string> $messages */
        $messages = $browser->script('return window.messagesSansReseau ?? [];')[0];

        return $messages;
    }

    /**
     * Le texte d'un élément, tel que le DOM le porte.
     */
    private function texteDe(string $selecteur): string
    {
        return 'document.querySelector('.json_encode($selecteur, JSON_THROW_ON_ERROR).').textContent.trim()';
    }

    /**
     * Si la page porte ce texte quelque part.
     */
    private function laPageDit(string $texte): string
    {
        return 'document.body.textContent.includes('.json_encode($texte, JSON_THROW_ON_ERROR).')';
    }

    /**
     * Coupe ou rend le réseau de la page, par les outils de Chrome.
     */
    private function couperLeReseau(ChromeDevToolsDriver $outils, bool $coupe): void
    {
        $outils->execute('Network.emulateNetworkConditions', [
            'offline' => $coupe,
            'latency' => 0,
            'downloadThroughput' => -1,
            'uploadThroughput' => -1,
        ]);
    }

    /**
     * Attend que le magasin garde une copie de chaque chemin : elles s'écrivent
     * au repos, après la page qu'elles gardent, et le préchauffage les demande
     * l'une après l'autre.
     *
     * @param  list<string>  $chemins
     */
    private function attendreLesCopies(Browser $browser, array $chemins, int $secondes = 30): void
    {
        $limite = microtime(true) + $secondes;

        do {
            /** @var list<array{chemin: string, composant: string}> $copies */
            $copies = $browser->driver->executeAsyncScript(self::LIRE_LES_COPIES);
            $manquants = array_values(array_diff($chemins, array_column($copies, 'chemin')));

            if ($manquants === []) {
                return;
            }

            usleep(250_000);
        } while (microtime(true) < $limite);

        $this->fail('le magasin n’a pas gardé : '.implode(', ', $manquants));
    }
}
