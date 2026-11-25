<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Enums\PersonalRecordType;
use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\User;
use Facebook\WebDriver\Exception\TimeoutException;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ParcoursHorsLigne;

/**
 * La page Records liste les records du compte, exercice par exercice (#2434),
 * et n'est jamais gardée hors ligne : ses chiffres sont ceux du compte.
 *
 * En ligne, le parcours part de Progrès, où une carte « Records » mène à la
 * page : la charge max avec ses répétitions, le 1RM estimé et le meilleur
 * volume, chacun avec son jour. Le retour mène à Progrès, qui reste l'onglet
 * allumé, et un seul titre de niveau 1 se voit. Les marges se mesurent à 375 et
 * à 320 px, la barre de défilement masquée.
 *
 * Sans réseau, la copie de Progrès n'offre plus la carte, et une navigation
 * complète sur /records n'ouvre que la page hors ligne, sans un record du
 * compte ; le réseau rendu, la page s'ouvre sur ses records.
 */
class RecordsTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    private const string NOM_LONG = 'Développé couché incliné à la barre guidée prise large';

    /**
     * Les records du compte à l'écran : 140 kg × 3 (le poids max du squat), 154 kg (son 1RM estimé) et 420 kg
     * (son meilleur volume). Lu en `innerText`, sur une seule ligne : `waitUntil` la fait suivre d'un `return`.
     */
    private const string LES_RECORDS_SONT_A_L_ECRAN = "(() => { const texte = document.querySelector('#main-content')?.innerText ?? ''; return /(?<![\\d.,])140[\\s\\u202f\\u00a0]*kg[\\s\\u202f\\u00a0]*×[\\s\\u202f\\u00a0]*3/.test(texte) && /(?<![\\d.,])154[\\s\\u202f\\u00a0]*kg/.test(texte) && /(?<![\\d.,])420[\\s\\u202f\\u00a0]*kg/.test(texte); })()";

    /**
     * Le dégagement des cartes : leur marge à l'écran, et celle de chaque
     * texte (le nom, les libellés et les valeurs) au bord de sa carte, en
     * pixels. La barre de défilement est masquée pour mesurer la largeur que
     * l'écran a vraiment.
     */
    private const string MESURER_LES_MARGES = <<<'JS'
        const style = document.createElement('style');
        style.textContent = 'html { scrollbar-width: none; } html::-webkit-scrollbar { display: none; }';
        document.head.appendChild(style);
        const largeur = document.documentElement.clientWidth;
        const cartes = [...document.querySelectorAll('#main-content [data-record] > *')];
        const lettres = (element) => {
            const plage = document.createRange();
            plage.selectNodeContents(element);

            return plage.getBoundingClientRect();
        };
        const mesures = cartes.map((carte) => {
            const cadre = carte.getBoundingClientRect();
            const nom = carte.querySelector('a > span');
            const textes = [nom, ...carte.querySelectorAll('dt > span, dd')].map((element) =>
                element === nom ? element.getBoundingClientRect() : lettres(element),
            );

            return {
                gauche: cadre.left,
                droite: largeur - cadre.right,
                texteAGauche: Math.min(...textes.map((texte) => texte.left - cadre.left)),
                texteADroite: Math.min(...textes.map((texte) => cadre.right - texte.right)),
            };
        });
        style.remove();

        return { largeur, innerWidth, cartes: mesures };
        JS;

    private function unCompteAvecDesRecords(): User
    {
        $compte = User::factory()->create([
            'email' => 'records-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);

        $squat = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Squat', 'type' => 'strength']);
        $this->poserLesRecords($compte, $squat, 140, 3, 154, 420, '2026-11-10 09:30:00');

        $long = Exercise::factory()->create(['user_id' => $compte->id, 'name' => self::NOM_LONG, 'type' => 'strength']);
        $this->poserLesRecords($compte, $long, 62.5, 8, 78.5, 500, '2026-10-02 18:00:00');

        $autre = User::factory()->create(['email_verified_at' => now()]);
        $this->poserLesRecords($autre, Exercise::factory()->create(['user_id' => $autre->id, 'name' => 'Exercice du voisin', 'type' => 'strength']), 999, 1, 999, 999, '2026-11-18 09:00:00');

        return $compte;
    }

    private function poserLesRecords(User $compte, Exercise $exercice, float $poids, int $repetitions, float $unRm, float $volume, string $jour): void
    {
        foreach ([
            [PersonalRecordType::MaxWeight, $poids, $repetitions],
            [PersonalRecordType::Max1RM, $unRm, $poids],
            [PersonalRecordType::MaxVolumeSet, $volume, null],
        ] as [$type, $valeur, $secondaire]) {
            PersonalRecord::factory()->createQuietly([
                'user_id' => $compte->id,
                'exercise_id' => $exercice->id,
                'workout_id' => null,
                'type' => $type,
                'value' => $valeur,
                'secondary_value' => $secondaire,
                'achieved_at' => $jour,
            ]);
        }
    }

    public function test_en_ligne_records_dit_les_records_du_compte_depuis_progres(): void
    {
        $compte = $this->unCompteAvecDesRecords();

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->resizeToIphone15()
                ->visit('/stats')
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('#main-content a[href$="/records"]', 30)
                ->clickWhenSettled('#main-content a[href$="/records"]')
                ->waitForLocation('/records', 15)
                ->waitFor('#main-content', 30)
                ->waitUntil(self::LES_RECORDS_SONT_A_L_ECRAN, 30, 'les records du compte ne sont pas à l’écran')
                ->assertScript("[...document.querySelectorAll('h1')].filter((titre) => titre.getClientRects().length > 0).length", 1)
                ->assertScript("[...document.querySelectorAll('h1')].find((titre) => titre.getClientRects().length > 0).textContent.trim().toLowerCase()", 'records')
                ->assertScript("document.querySelectorAll('#main-content [data-record]').length", 2)
                ->assertScript("document.querySelector('#main-content').textContent.includes('Exercice du voisin')", false)
                ->assertScript("document.querySelector('#main-content').textContent.includes('999')", false)
                ->assertScript("document.querySelector('#main-content').innerText.includes('10 nov.')", true)
                ->assertScript("new URL(document.querySelector('#main-content [data-record] a').href).pathname.startsWith('/exercises/')", true);

            // Le retour mène à Progrès, qui reste l'onglet allumé.
            $browser->waitFor('header a[aria-label="Retour"]', 15)
                ->assertScript("new URL(document.querySelector('header a[aria-label=\"Retour\"]').href).pathname", '/stats')
                ->assertScript("document.querySelectorAll('nav[aria-label=\"Navigation principale\"] a[aria-current=\"page\"]').length", 1)
                ->assertScript("new URL(document.querySelector('nav[aria-label=\"Navigation principale\"] a[aria-current=\"page\"]').href).pathname", '/stats')
                ->clickWhenSettled('header a[aria-label="Retour"]')
                ->waitForLocation('/stats', 15)
                ->assertNoConsoleExceptions();
        });
    }

    public function test_records_laisse_une_marge_a_chaque_carte_et_a_chaque_texte_a_375_et_a_320_px(): void
    {
        $compte = $this->unCompteAvecDesRecords();

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte);

            foreach ([375, 320] as $largeur) {
                $browser->resize($largeur, 812)
                    ->visit('/records')
                    ->disableAnimations()
                    ->waitFor('#main-content [data-record]', 30)
                    ->waitUntil(self::LES_RECORDS_SONT_A_L_ECRAN, 30, 'les records du compte ne sont pas à l’écran')
                    ->waitForStableLayout();

                /** @var array{largeur: int, innerWidth: int, cartes: list<array{gauche: float, droite: float, texteAGauche: float, texteADroite: float}>} $mesure */
                $mesure = $browser->script(self::MESURER_LES_MARGES)[0];

                $this->assertSame($largeur, $mesure['largeur'], "La fenêtre n'a pas la largeur demandée ({$largeur} px) : la mesure ne vaudrait rien.");
                $this->assertCount(2, $mesure['cartes']);

                foreach ($mesure['cartes'] as $rang => $carte) {
                    $this->assertGreaterThanOrEqual(12.0, $carte['gauche'], "À {$largeur} px, la carte {$rang} colle au bord gauche de l'écran.");
                    $this->assertGreaterThanOrEqual(12.0, $carte['droite'], "À {$largeur} px, la carte {$rang} colle au bord droit de l'écran.");
                    $this->assertGreaterThanOrEqual(12.0, $carte['texteAGauche'], "À {$largeur} px, un texte de la carte {$rang} colle au bord gauche de sa carte.");
                    $this->assertGreaterThanOrEqual(12.0, $carte['texteADroite'], "À {$largeur} px, un texte de la carte {$rang} colle au bord droit de sa carte.");
                }
            }

            $browser->assertNoConsoleExceptions();
        });
    }

    public function test_au_bureau_deux_cartes_d_une_meme_rangee_ont_la_meme_hauteur_meme_avec_un_seul_record(): void
    {
        $compte = $this->unCompteAvecDesRecords();
        $court = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Traction', 'type' => 'strength']);
        PersonalRecord::factory()->createQuietly([
            'user_id' => $compte->id,
            'exercise_id' => $court->id,
            'workout_id' => null,
            'type' => PersonalRecordType::MaxWeight,
            'value' => 20,
            'secondary_value' => 5,
            'achieved_at' => '2026-11-12 09:00:00',
        ]);

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->resize(1280, 800)
                ->visit('/records')
                ->disableAnimations()
                ->waitFor('#main-content [data-record]', 30)
                ->waitUntil(self::LES_RECORDS_SONT_A_L_ECRAN, 30, 'les records du compte ne sont pas à l’écran')
                ->waitForStableLayout();

            $ecart = $browser->script(<<<'JS'
                const lignes = new Map();
                for (const li of document.querySelectorAll('#main-content [data-record]')) {
                    const haut = Math.round(li.getBoundingClientRect().top);
                    const carte = li.firstElementChild.getBoundingClientRect().height;
                    const colonne = li.getBoundingClientRect().height;
                    lignes.set(haut, [...(lignes.get(haut) ?? []), { carte, colonne }]);
                }
                const plusDeUne = [...lignes.values()].filter((rangee) => rangee.length > 1);
                return { rangeesDeDeux: plusDeUne.length, ecart: Math.max(0, ...plusDeUne.flat().map((c) => Math.abs(c.carte - c.colonne))) };
            JS)[0];

            $this->assertGreaterThanOrEqual(1, $ecart['rangeesDeDeux'], 'Au bureau, les cartes ne sont pas sur deux colonnes : la mesure ne vaudrait rien.');
            $this->assertLessThanOrEqual(1.0, $ecart['ecart'], 'Une carte est plus basse que sa rangée.');
            $browser->assertNoConsoleExceptions();
        });
    }

    public function test_sans_reseau_records_n_ouvre_que_la_page_hors_ligne_sans_un_record(): void
    {
        $compte = $this->unCompteAvecDesRecords();

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->visit('/records')
                ->waitFor('#main-content', 30)
                ->waitUntil(self::LES_RECORDS_SONT_A_L_ECRAN, 30, 'les records du compte ne sont pas à l’écran en ligne');

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);

            // La racine de Progrès est gardée : sa copie se range, et elle ne porte pas la carte Records.
            $browser->clickWhenSettled('header a[aria-label="Retour"]')
                ->waitForLocation('/stats', 15)
                ->waitFor('#main-content a[href$="/records"]', 30);

            // Le profil, que l'onglet du bas rouvrira : sa copie, et celle de Progrès que la page vient de recevoir.
            $browser->clickWhenSettled('[dusk="nav-profile"]')
                ->waitForLocation('/profile', 15);
            $this->attendreLesCopies($browser, ['/stats', '/profile']);

            $this->noterLesMessages($browser);

            $outils = $this->outilsDuNavigateur($browser);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser): void {
                // La copie de Progrès, sans accès à Records.
                $browser->clickWhenSettled('[dusk="nav-stats"]');
                $this->attendreLaCopie($browser, '/stats');
                $browser->waitFor('#main-content [data-indisponible-hors-ligne]', 15)
                    ->assertMissing('#main-content a[href$="/records"]');

                // Une navigation complète sur Records, sans copie : la page qui dit ce qui s'ouvre sans réseau, sans un record.
                $this->attendreLaCoquilleServieA($browser->visit('/records'), '/records')
                    ->waitUsing(15, 200, fn (): bool => (bool) $browser->script('return document.querySelector(\'[dusk="page-hors-ligne-sans-copie"], [dusk="page-hors-ligne"]\') !== null')[0])
                    ->assertPathIs('/records')
                    ->assertScript('!/(?<![\d.,])(140|154|420|62[.,]5)(?![\d])/.test(document.documentElement.outerHTML)')
                    ->assertScript("!document.documentElement.outerHTML.includes('Squat')");
            });

            // Le réseau rendu, la page s'ouvre sur ses records.
            $browser->visit('/records')
                ->waitFor('#main-content', 30)
                ->waitUntil(self::LES_RECORDS_SONT_A_L_ECRAN, 30, 'les records ne reviennent pas avec le réseau');
        });
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
