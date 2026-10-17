<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\PagesGardees;

/**
 * La page garde, chiffrées, les copies des pages de salle qu'elle reçoit du
 * réseau, et la déconnexion les efface avant de partir (#2048, partie D).
 *
 * Les copies vivent dans IndexedDB (`gym-tracker-copies`), que seule la page
 * ouvre : ce parcours les lit par script, comme un autre programme de
 * l'appareil les lirait. Il prouve dans un vrai Chrome ce que les tests de
 * Vitest jouent sur une base en mémoire : l'accueil ouvert par un chargement
 * complet (la page initiale du document, pour laquelle Inertia n'émet rien)
 * et la séance en cours ouverte depuis lui sont gardés ; rien de ce qu'ils
 * montrent ne se lit en clair dans la base ; la clé du témoin, et elle seule,
 * déchiffre la séance ; et la déconnexion par le menu « Plus » vide le
 * magasin et ferme son état AVANT de partir (décision 6 du propriétaire) :
 * sans réseau, elle n'a pas lieu, et les copies sont pourtant oubliées. Le
 * réseau est coupé par les outils de Chrome, sur la page : la déconnexion,
 * une requête de la page que le worker ne prend pas, y échoue comme sans
 * réseau. La page de connexion, où mène ensuite la déconnexion acceptée,
 * oublierait les copies d'elle-même (le titulaire de l'appareil change) :
 * seul le passage sans réseau prouve l'oubli avant l'envoi.
 *
 * Le chiffrement exige un contexte sûr : le serveur ne demande l'historique
 * chiffré, sans lequel rien n'est gardé, qu'en HTTPS ou sur la boucle locale
 * (`HandleInertiaRequests::leNavigateurPeutChiffrer()`). La CI sert
 * 127.0.0.1, où Chrome tient l'origine pour sûre ; sous Sail, servi en http
 * sur `laravel.test`, le parcours se déclare sauté.
 */
class CopiesHorsLigneTest extends DuskTestCase
{
    /**
     * Ce que la base garde, lu par script : chaque copie, ses champs et tout
     * son contenu en texte (les octets en latin1, où un texte ASCII se lit
     * tel quel), et l'état du magasin. Une base absente ne se crée pas ici.
     */
    private const string LIRE_LE_MAGASIN = <<<'JS'
        const fini = arguments[arguments.length - 1];
        const enTexte = (valeur) =>
            valeur instanceof ArrayBuffer || ArrayBuffer.isView(valeur)
                ? new TextDecoder('latin1').decode(valeur)
                : valeur;
        const ouverture = indexedDB.open('gym-tracker-copies');
        ouverture.onupgradeneeded = () => ouverture.transaction.abort();
        ouverture.onerror = () => fini({ base: false, copies: [], etat: null });
        ouverture.onsuccess = () => {
            const base = ouverture.result;
            const transaction = base.transaction(['pages', 'etat'], 'readonly');
            const pages = transaction.objectStore('pages').getAll();
            const etat = transaction.objectStore('etat').get('etat');
            transaction.oncomplete = () => {
                base.close();
                fini({
                    base: true,
                    copies: pages.result.map((copie) => ({
                        chemin: copie.chemin,
                        composant: copie.composant,
                        champs: Object.keys(copie).sort(),
                        texte: JSON.stringify(
                            Object.fromEntries(Object.entries(copie).map(([cle, valeur]) => [cle, enTexte(valeur)])),
                        ),
                    })),
                    etat: etat.result ?? null,
                    etatEnTexte: JSON.stringify(etat.result ?? null),
                });
            };
        };
    JS;

    /**
     * Déchiffre une copie par la clé que porte le témoin, avec les données
     * authentifiées du magasin (`compte|chemin|contrat`) : rend la page, ou
     * la raison de l'échec.
     */
    private const string DECHIFFRER_UNE_COPIE = <<<'JS'
        const [chemin, compte, contrat, fini] = arguments;
        (async () => {
            const temoin = document.cookie
                .split('; ')
                .map((morceau) => morceau.split('='))
                .find(([nom]) => nom === '__Host-gt_copies' || nom === 'gt_copies');
            if (!temoin) {
                return { erreur: 'sans-temoin' };
            }
            const binaire = atob(`${temoin[1].replace(/-/g, '+').replace(/_/g, '/')}=`);
            const cle = await crypto.subtle.importKey(
                'raw',
                Uint8Array.from(binaire, (lettre) => lettre.charCodeAt(0)),
                { name: 'AES-GCM' },
                false,
                ['decrypt'],
            );
            const base = await new Promise((ouverte, refusee) => {
                const demande = indexedDB.open('gym-tracker-copies');
                demande.onsuccess = () => ouverte(demande.result);
                demande.onerror = () => refusee(demande.error);
            });
            const copie = await new Promise((lue, refusee) => {
                const demande = base.transaction('pages').objectStore('pages').get(chemin);
                demande.onsuccess = () => lue(demande.result);
                demande.onerror = () => refusee(demande.error);
            });
            base.close();
            if (!copie) {
                return { erreur: 'sans-copie' };
            }
            const clair = await crypto.subtle.decrypt(
                { name: 'AES-GCM', iv: copie.iv, additionalData: new TextEncoder().encode(`${compte}|${chemin}|${contrat}`) },
                cle,
                copie.chiffre,
            );
            const { page } = JSON.parse(new TextDecoder().decode(clair));

            return {
                composant: page.component,
                seance: page.props?.workout?.name ?? null,
                compte: page.props?.auth?.user?.name ?? null,
            };
        })().then(fini, (erreur) => fini({ erreur: String(erreur) }));
    JS;

    public function test_les_copies_s_ecrivent_chiffrees_et_la_deconnexion_par_le_menu_les_efface(): void
    {
        $jetons = [
            'compte' => Str::random(12),
            'adresse' => Str::lower(Str::random(12)),
            'seance' => Str::random(12),
            'exercice' => Str::random(12),
        ];
        $compte = User::factory()->create([
            'name' => 'Camille '.$jetons['compte'],
            'email' => 'copies-'.$jetons['adresse'].'@example.org',
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

        $this->browse(function (Browser $browser) use ($compte, $seance, $jetons, $cheminDeLaSeance): void {
            $browser->visit('/login')
                ->waitFor('input[type="email"]', 30)
                ->type('input[type="email"]', $compte->email)
                ->type('input[type="password"]', 'password')
                ->click('[data-testid="login-button"]')
                ->waitForLocation('/dashboard', 15);

            if ($browser->script("return window.isSecureContext === true && 'indexedDB' in window && !!window.crypto?.subtle;")[0] !== true) {
                $this->markTestSkipped('Origine en http hors boucle locale : l’historique n’y est pas chiffré, et rien n’est gardé.');
            }

            // L'accueil par un chargement complet, sans rien toucher, puis la séance en cours depuis lui.
            $browser->visit('/dashboard')
                ->disableAnimations()
                ->waitFor('[dusk="active-workout-banner"]', 30)
                ->clickWhenSettled('[dusk="active-workout-banner"]')
                ->waitForLocation($cheminDeLaSeance, 15)
                ->waitForText($jetons['exercice'], 15, true);

            $magasin = $this->attendreLesCopies($browser, ['/dashboard', $cheminDeLaSeance]);
            $parChemin = array_column($magasin['copies'], null, 'chemin');

            $this->assertSame('Dashboard', $parChemin['/dashboard']['composant']);
            $this->assertSame('Workouts/Show', $parChemin[$cheminDeLaSeance]['composant']);
            $this->assertLessThanOrEqual(10, count($magasin['copies']), 'plus de copies que de pages gardées pour ce compte');
            $this->assertIsArray($magasin['etat']);
            $this->assertTrue($magasin['etat']['ouvert']);
            $this->assertSame((string) $compte->id, $magasin['etat']['compte']);
            $this->assertSame(PagesGardees::versionDuContrat(), $magasin['etat']['contrat']);

            foreach ($magasin['copies'] as $copie) {
                $this->assertSame(
                    ['chemin', 'chiffre', 'composant', 'copieDu', 'ecriteLe', 'empreinte', 'iv'],
                    $copie['champs'],
                    "la copie de {$copie['chemin']} garde autre chose que son chemin, son composant, ses dates, l’empreinte et le chiffré",
                );

                foreach ($jetons as $quoi => $jeton) {
                    $this->assertStringNotContainsString($jeton, $copie['texte'], "la copie de {$copie['chemin']} montre en clair : {$quoi}");
                }
            }

            foreach ($jetons as $quoi => $jeton) {
                $this->assertStringNotContainsString($jeton, $magasin['etatEnTexte'], "l’état du magasin montre en clair : {$quoi}");
            }

            // La clé du témoin, et elle seule, déchiffre la séance telle que la page l'a reçue.
            $dechiffree = $browser->driver->executeAsyncScript(
                self::DECHIFFRER_UNE_COPIE,
                [$cheminDeLaSeance, (string) $compte->id, PagesGardees::versionDuContrat()],
            );

            // Le pilote rend les clés d'un objet dans son ordre à lui : seules les paires comptent.
            $this->assertEquals(
                ['composant' => 'Workouts/Show', 'seance' => $seance->name, 'compte' => $compte->name],
                $dechiffree,
            );

            // La déconnexion par le menu « Plus », d'abord sans réseau : elle n'a pas lieu, les copies sont oubliées.
            $browser->clickWhenSettled('[dusk="nav-profile"]')
                ->waitForLocation('/profile', 15)
                ->waitFor('[data-testid="logout-button"]', 15);

            $outils = new ChromeDevToolsDriver($browser->driver);
            $outils->execute('Network.enable');
            $this->couperLeReseau($outils, true);

            try {
                $browser->clickWhenSettled('[data-testid="logout-button"]')
                    ->waitUntil('document.body.textContent.includes('.json_encode("Pas de réseau : la déconnexion n'a pas eu lieu.", JSON_THROW_ON_ERROR).')', 15)
                    ->assertPathIs('/profile');

                $sansReseau = $this->lireLeMagasin($browser);

                $this->assertSame([], $sansReseau['copies'], 'la déconnexion sans réseau a laissé les copies');
                $this->assertIsArray($sansReseau['etat']);
                $this->assertFalse($sansReseau['etat']['ouvert']);
                $this->assertContains($magasin['etat']['empreinte'], $sansReseau['etat']['empreintesFermees'], 'la clé de la session n’est pas fermée');
            } finally {
                $this->couperLeReseau($outils, false);
            }

            // Le réseau revenu, la même déconnexion aboutit.
            $browser->clickWhenSettled('[data-testid="logout-button"]')
                ->waitForLocation('/login', 15)
                ->waitFor('input[type="email"]', 15);

            $apres = $this->lireLeMagasin($browser);

            $this->assertSame([], $apres['copies'], 'des copies restent après la déconnexion');
            $this->assertIsArray($apres['etat']);
            $this->assertFalse($apres['etat']['ouvert']);
            $this->assertNull($apres['etat']['compte']);
            $this->assertContains($magasin['etat']['empreinte'], $apres['etat']['empreintesFermees'], 'la clé de la session n’est pas fermée');
            $this->assertFalse(
                $browser->script("return document.cookie.split('; ').some((morceau) => /^(__Host-)?gt_copies=/.test(morceau));")[0],
                'le témoin reste après la déconnexion',
            );
            $this->assertNull($browser->script("return localStorage.getItem('gym-tracker:ecritures-acceptees');")[0]);
        });
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
     * @return array{base: bool, copies: list<array{chemin: string, composant: string, champs: list<string>, texte: string}>, etat: array<string, mixed>|null, etatEnTexte: string}
     */
    private function lireLeMagasin(Browser $browser): array
    {
        /** @var array{base: bool, copies: list<array{chemin: string, composant: string, champs: list<string>, texte: string}>, etat: array<string, mixed>|null, etatEnTexte: string} $magasin */
        $magasin = $browser->driver->executeAsyncScript(self::LIRE_LE_MAGASIN);

        return $magasin;
    }

    /**
     * Attend que le magasin garde une copie de chaque chemin : elles s'écrivent
     * au repos, après la page qu'elles gardent.
     *
     * @param  list<string>  $chemins
     * @return array{base: bool, copies: list<array{chemin: string, composant: string, champs: list<string>, texte: string}>, etat: array<string, mixed>|null, etatEnTexte: string}
     */
    private function attendreLesCopies(Browser $browser, array $chemins, int $secondes = 30): array
    {
        $limite = microtime(true) + $secondes;

        do {
            $magasin = $this->lireLeMagasin($browser);
            $manquants = array_values(array_diff($chemins, array_column($magasin['copies'], 'chemin')));

            if ($manquants === []) {
                return $magasin;
            }

            usleep(250_000);
        } while (microtime(true) < $limite);

        $this->fail('le magasin n’a pas gardé : '.implode(', ', $manquants));
    }
}
