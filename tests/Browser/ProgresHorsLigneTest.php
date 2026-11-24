<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\User;
use Facebook\WebDriver\Exception\TimeoutException;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ParcoursHorsLigne;

/**
 * Sans réseau, Progrès s'ouvre sur une racine légère, sans une seule mesure
 * (#2434, lot 4a).
 *
 * Progrès n'était pas une page gardée : sans réseau, l'onglet et le retour de
 * la bibliothèque d'exercices, dont la flèche mène à Progrès, tombaient sur
 * « Hors ligne ». La page est désormais gardée, et le dépôt refuse toujours
 * qu'une copie garde une mesure ou un objectif : la pesée, sa variation, la
 * masse grasse et le groupe différé qui porte leurs historiques n'entrent dans
 * aucune copie (`PROPS_JAMAIS_GARDEES`). Le lot 4b les a rangés sous la page
 * Corps, que la racine n'offre plus qu'en ligne. Le parcours le joue dans un
 * vrai Chrome : en ligne, Corps montre le poids et la masse grasse du compte
 * (le témoin positif : sans lui, leur absence hors ligne ne prouverait rien),
 * Progrès n'en montre plus aucun et mène à Corps, et la copie rangée de
 * Progrès, déchiffrée, n'en porte aucun chiffre ; puis, la coupure
 * faite, la flèche de la bibliothèque et l'onglet Progrès ouvrent la racine
 * légère, où une seule phrase dit que les chiffres reviennent avec le réseau
 * (ni carte, ni choix de période, ni accès à Corps, Records, Objectifs ou Badges, seule la tuile Exercices),
 * et rien d'autre n'est dit. Le réseau rendu, la page se
 * recharge, l'accès à Corps revient et la page des mesures s'ouvre.
 *
 * Comme les autres parcours hors ligne, il exige un contexte sûr, et rend
 * public/build introuvable pendant la coupure (`.ai/rules/browser.md`).
 */
class ProgresHorsLigneTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    private const string POIDS = '83.70';

    private const string MASSE_GRASSE = '19.30';

    /**
     * Si le contenu de la page porte le poids ou la masse grasse du compte,
     * sous la forme que `nombre()` lui donne (point ou virgule).
     */
    private const string LES_MESURES_SONT_A_L_ECRAN = <<<'SCRIPT'
        (() => /\b83[.,]7\b|\b19[.,]3\b/.test(document.querySelector('#main-content')?.textContent ?? ''))()
    SCRIPT;

    public function test_sans_reseau_l_onglet_progres_et_le_retour_de_la_bibliotheque_ouvrent_la_racine_sans_mesures(): void
    {
        $compte = User::factory()->create([
            'email' => 'progres-hors-ligne-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
        Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Exercice '.Str::random(12), 'type' => 'strength']);
        BodyMeasurement::factory()->create([
            'user_id' => $compte->id,
            'weight' => self::POIDS,
            'body_fat' => self::MASSE_GRASSE,
            'measured_at' => now()->subDay()->toDateString(),
            'notes' => null,
        ]);

        $this->browse(function (Browser $browser) use ($compte): void {
            // Corps en ligne : les mesures sont à l'écran, et les cartes ne sont plus des squelettes.
            $browser->loginAs($compte)
                ->visit('/corps')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);

            $browser->disableAnimations()
                ->waitUntil(self::LES_MESURES_SONT_A_L_ECRAN, 30, 'le poids et la masse grasse ne sont pas à l’écran sur Corps en ligne')
                ->waitUntil("document.querySelectorAll('#main-content .glass-skeleton').length === 0", 30, 'les cartes de Corps attendent encore leurs données en ligne');

            // Progrès en ligne : plus une seule mesure, et l'accès à Corps.
            $browser->clickWhenSettled('[dusk="nav-stats"]')
                ->waitForLocation('/stats', 15)
                ->waitFor('#main-content a[href$="/corps"]', 30)
                ->waitUntil("document.querySelectorAll('#main-content .glass-skeleton').length === 0", 30, 'les cartes de Progrès attendent encore leurs données en ligne')
                ->assertScript(self::LES_MESURES_SONT_A_L_ECRAN, false);

            // Profil, puis la bibliothèque, par la tuile de Progrès : leurs copies, et celle de Progrès, que la page vient de recevoir.
            $browser->clickWhenSettled('[dusk="nav-profile"]')
                ->waitForLocation('/profile', 15)
                ->clickWhenSettled('[dusk="nav-stats"]')
                ->waitForLocation('/stats', 15)
                ->waitFor('#main-content a[href$="/exercises"]', 30)
                ->clickWhenSettled('#main-content a[href$="/exercises"]')
                ->waitForLocation('/exercises', 15);

            $magasin = $this->attendreLesCopies($browser, ['/stats', '/profile', '/exercises']);
            $this->assertSame('Stats/Index', collect($magasin['copies'])->firstWhere('chemin', '/stats')['composant'] ?? null);

            $this->assertCopieDeProgresSansMesures($browser, $compte);

            $this->noterLesMessages($browser);

            $outils = $this->outilsDuNavigateur($browser);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser): void {
                // La flèche de la bibliothèque mène à Progrès : sa copie, et non la page « Hors ligne ».
                $browser->clickWhenSettled('header a[aria-label="Retour"]');
                $this->attendreLaRacineSansMesures($browser);

                // Puis l'onglet Progrès, depuis le profil.
                $browser->clickWhenSettled('[dusk="nav-profile"]');
                $this->attendreLaCopie($browser, '/profile');
                $browser->clickWhenSettled('[dusk="nav-stats"]');
                $this->attendreLaRacineSansMesures($browser);

                // Rien n'a été dit : ni page indisponible, ni « Mise à jour à terminer avec le réseau ».
                $this->assertSame([], $this->messagesSansReseau($browser));

                // La cloche, posée dans le coin de la première carte, ne recouvre pas la phrase (le dernier enfant de la carte, après l'icône) et ne dépasse pas sous la carte : à 320 px la phrase passe sous elle, à 402 px la carte est trop basse.
                foreach ([320, 402] as $largeur) {
                    $browser->resize($largeur, 844)
                        ->waitForStableLayout()
                        ->assertScript(<<<'JS'
                            (() => {
                                const carte = document.querySelector('#main-content [data-indisponible-hors-ligne]');
                                const texte = carte.lastElementChild.getBoundingClientRect();
                                const cloche = document.querySelector('#main-content a[href$="/notifications"]').getBoundingClientRect();
                                const separes = texte.right <= cloche.left || texte.left >= cloche.right || texte.bottom <= cloche.top || texte.top >= cloche.bottom;
                                return separes && cloche.bottom <= carte.getBoundingClientRect().bottom;
                            })()
                            JS, true);
                }

                // Aucun contrôle qui mènerait à « Hors ligne » : pas de période (`/stats?period=…` n'est jamais gardée).
                $browser->assertScript("[...document.querySelectorAll('#main-content button')].some((b) => /^(\\d+ jours|\\d+ mois|1 an)$/.test(b.textContent.trim()))", false)
                    ->assertScript("document.querySelectorAll('#main-content [role=\"combobox\"], #main-content select').length", 0)
                    ->assertPathIs('/stats');
            });

            // Le réseau rendu, la copie se recharge : le bandeau part, l'accès à Corps revient, et Corps montre les mesures.
            $browser->waitUntilMissing('[dusk="bandeau-hors-ligne"]', 90)
                ->assertPathIs('/stats')
                ->waitFor('#main-content a[href$="/corps"]', 30)
                ->clickWhenSettled('#main-content a[href$="/corps"]')
                ->waitForLocation('/corps', 15)
                ->waitUntil(self::LES_MESURES_SONT_A_L_ECRAN, 30, 'les mesures ne reviennent pas avec le réseau');
        });
    }

    /**
     * La copie de Progrès que le magasin garde, déchiffrée : ses props sont
     * celles de la racine légère, et aucun chiffre de mesure n'y figure.
     */
    private function assertCopieDeProgresSansMesures(Browser $browser, User $compte): void
    {
        $cle = $this->temoinDesCopies($browser);
        $this->assertIsString($cle, 'le témoin des copies manque');

        $copie = collect($this->dechiffrerLeMagasin($browser, $cle, (string) $compte->id))->firstWhere('chemin', '/stats');
        $this->assertIsArray($copie, 'la copie de Progrès manque');
        $this->assertIsString($copie['texte'], 'la copie de Progrès ne se déchiffre pas');

        $contenu = json_decode($copie['texte'], true, flags: JSON_THROW_ON_ERROR);
        $page = is_array($contenu) ? ($contenu['page'] ?? null) : null;
        $props = is_array($page) ? ($page['props'] ?? null) : null;
        $this->assertIsArray($props, 'la copie de Progrès n’a pas de props');

        $noms = array_map(strval(...), array_keys($props));
        sort($noms);
        $this->assertSame(['auth', 'errors', 'selectedPeriod'], array_values(array_diff($noms, ['flash', 'is_local', 'is_testing', 'pending_migrations', 'social_login_enabled', 'vapidPublicKey'])));
        $this->assertDoesNotMatchRegularExpression('/83\.70|19\.30|latestWeight|weightChange|bodyFat|weightHistory|deferredData/', json_encode($props, JSON_THROW_ON_ERROR));
    }

    /**
     * Attend la racine de Progrès servie de sa copie : son adresse, son
     * bandeau daté, une seule phrase pour les mesures, aucun squelette, aucune
     * mesure à l'écran, et pas la page « Hors ligne ».
     */
    private function attendreLaRacineSansMesures(Browser $browser): void
    {
        $this->attendreLaCopie($browser, '/stats');

        $browser->waitFor('#main-content [data-indisponible-hors-ligne]', 15)
            ->assertScript("document.querySelectorAll('#main-content [data-indisponible-hors-ligne]').length", 1)
            ->assertScript("document.querySelector('#main-content [data-indisponible-hors-ligne]').textContent.includes('Tes chiffres reviennent avec le réseau.')", true)
            ->waitUntil("document.querySelectorAll('#main-content .glass-skeleton').length === 0", 15, 'une carte de Progrès pulse sans fin hors ligne')
            ->assertMissing('#main-content a[href$="/corps"]')
            ->assertMissing('#main-content a[href$="/records"]')
            ->assertMissing('#main-content a[href$="/goals"]')
            ->assertMissing('#main-content a[href$="/achievements"]')
            ->assertScript("[...document.querySelectorAll('#main-content [dusk=\"tuiles-d-acces\"] a')].map((a) => new URL(a.href).pathname)", ['/exercises'])
            ->assertMissing('[dusk="page-hors-ligne"]')
            ->assertScript('(() => /\\b83[.,]7\\b|\\b19[.,]3\\b/.test(document.querySelector("#main-content")?.textContent ?? ""))()', false);
    }

    /**
     * Attend la copie d'une page de salle, ouverte depuis la page précédente :
     * son adresse, son bandeau daté et son entrée d'historique, chiffrée.
     */
    private function attendreLaCopie(Browser $browser, string $chemin): void
    {
        try {
            $browser->waitForLocation($chemin, 15);
        } catch (TimeoutException) {
            $this->fail("la copie de {$chemin} ne s’est pas ouverte ; la page a dit : ".implode(' | ', $this->messagesSansReseau($browser)));
        }

        $browser->waitFor('[dusk="bandeau-hors-ligne"]', 15)
            ->assertScript($this->leBandeauDit('Hors ligne'))
            ->waitUntil('history.state?.page instanceof ArrayBuffer', 15, "l’entrée d’historique de la copie de {$chemin} n’est pas chiffrée");
    }
}
