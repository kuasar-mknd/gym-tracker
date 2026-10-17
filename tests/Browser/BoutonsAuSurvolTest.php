<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Habit;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Les boutons des listes ne se cachent jusqu'au survol que sous une souris (#2082).
 *
 * À partir de 640 px, la bibliothèque, le journal, les habitudes, les
 * mensurations et le calculateur de disques cachaient leurs boutons
 * « Modifier » et « Supprimer » jusqu'au survol (`sm:opacity-0`). Sur une
 * tablette, qui ne survole pas, ils restaient transparents mais prenaient les
 * appuis. Ils ne se cachent plus que sous la variante `souris:` d'app.css,
 * `(hover: hover) and (pointer: fine)`.
 *
 * Le parcours joue les deux appareils dans Chromium. Sans tête, Chrome n'a pas
 * de pointeur (`pointer: none`, `hover: none`), et `Emulation.setEmulatedMedia`
 * accepte `hover` et `pointer` sans les appliquer (relevé dans Chromium 141,
 * quand `prefers-color-scheme` s'y applique). La souris se donne donc au
 * lancement, par les réglages de Blink (`--blink-settings`, pointeur principal
 * fin qui survole, `capacitesDuNavigateur()`), la tablette par
 * `Emulation.setTouchEmulationEnabled` (`pointer: coarse`, `hover: none`).
 * Retirer l'émulation tactile rend `pointer: none`, pas la souris du
 * lancement : le parcours tactile ferme son navigateur en finissant, et
 * ceux de la souris en démarrent un neuf, quel que soit leur ordre.
 *
 * À la souris, les actions d'une habitude ne se posent sur la cellule de son
 * nom que dans la colonne de 200 px (`sm:souris:absolute`) : un parcours le
 * vérifie aussi dans une fenêtre de 500 px, où la cellule prend toute la
 * carte.
 *
 * L'opacité se lit sur l'élément et tous ses ancêtres (`OPACITE_VUE`) : un
 * bouton garde l'opacité 1 dans un conteneur à 0. WebDriver la lit de même,
 * et tient pour caché un élément transparent (`.ai/rules/browser.md`).
 */
class BoutonsAuSurvolTest extends DuskTestCase
{
    /**
     * Un pointeur principal fin qui survole : une souris.
     *
     * Les valeurs sont celles des types de Blink (`HoverType` : 2 pour le
     * survol ; `PointerType` : 4 pour le pointeur fin).
     */
    private const string REGLAGES_D_UNE_SOURIS = '--blink-settings=primaryHoverType=2,availableHoverTypes=2,primaryPointerType=4,availablePointerTypes=4';

    /**
     * L'opacité que l'écran rend : celle de l'élément, multipliée par celle de
     * chacun de ses ancêtres. `null` si l'élément n'existe pas.
     */
    private const string OPACITE_VUE = <<<'JS'
        const element = document.querySelector(SELECTEUR);
        if (element === null) { return null; }
        let opacite = 1;
        for (let noeud = element; noeud !== null; noeud = noeud.parentElement) {
            opacite *= Number(getComputedStyle(noeud).opacity);
        }
        return opacite;
        JS;

    /** Ce que l'appareil annonce : la requête de `souris:`, et un pointeur grossier. */
    private const string LIRE_LE_POINTEUR = <<<'JS'
        return {
            souris: matchMedia('(hover: hover) and (pointer: fine)').matches,
            tactile: matchMedia('(pointer: coarse)').matches,
        };
        JS;

    /**
     * Donne le focus à l'élément qui précède la cible dans l'ordre de
     * tabulation, pour que la cible le reçoive d'une vraie touche Tab.
     */
    private const string FOCUS_AVANT = <<<'JS'
        const cible = document.querySelector(SELECTEUR);
        const tabulables = [...document.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])')]
            .filter((element) => element.getClientRects().length > 0);
        const rang = tabulables.indexOf(cible);
        if (rang < 1) { return false; }
        tabulables[rang - 1].focus();
        return true;
        JS;

    #[\Override]
    protected function capacitesDuNavigateur(): DesiredCapabilities
    {
        $capacites = parent::capacitesDuNavigateur();
        $options = $capacites->getCapability(ChromeOptions::CAPABILITY);

        if (! $options instanceof ChromeOptions) {
            $this->fail('Les capacités du navigateur ne portent pas d’options de Chrome.');
        }

        return $capacites->setCapability(ChromeOptions::CAPABILITY, $options->addArguments([self::REGLAGES_D_UNE_SOURIS]));
    }

    public function test_a_la_souris_les_boutons_attendent_le_survol_ou_le_focus(): void
    {
        $compte = $this->compte('souris');
        $exercice = Exercise::factory()->for($compte)->create(['name' => 'Tirage souris '.Str::random(6), 'category' => 'Dos']);
        BodyMeasurement::factory()->for($compte)->create(['weight' => 80.5, 'measured_at' => now()->subDay()->toDateString()]);

        $carte = '[dusk="exercise-card-'.$exercice->id.'"]';
        $modifier = '[dusk="edit-exercise-btn-desktop-'.$exercice->id.'"]';
        $supprimer = '[dusk="delete-exercise-btn-'.$exercice->id.'"]';
        $supprimerLaMesure = 'button[aria-label^="Supprimer la mesure du "]';

        $this->browse(function (Browser $browser) use ($compte, $carte, $modifier, $supprimer, $supprimerLaMesure): void {
            $browser->loginAs($compte)
                ->resize(1280, 900)
                ->visit('/exercises')
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor($carte, 15);

            $this->assertSame(['souris' => true, 'tactile' => false], $browser->script(self::LIRE_LE_POINTEUR)[0]);

            // La bibliothèque : le conteneur des boutons, révélé au survol de la carte et au focus d'un bouton.
            $this->amenerAuCentre($browser, $carte);
            $browser->mouseover('nav');
            $this->assertOpacite($browser, [$modifier => 0.0, $supprimer => 0.0], 'avant le survol de la carte');
            $browser->mouseover($carte);
            $this->assertOpacite($browser, [$modifier => 1.0, $supprimer => 1.0], 'au survol de la carte');
            $browser->mouseover('nav');
            $this->assertOpacite($browser, [$modifier => 0.0, $supprimer => 0.0], 'la souris partie');
            $this->tabulerVers($browser, $modifier);
            $this->assertOpacite($browser, [$modifier => 1.0, $supprimer => 1.0], 'au focus du clavier');

            // Les mensurations : le bouton lui-même, révélé au survol de sa carte et à son focus visible.
            $browser->visit('/body-measurements')
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                // Présent, pas visible : WebDriver tient le bouton transparent pour caché.
                ->waitUntil('document.querySelector('.json_encode($supprimerLaMesure, JSON_THROW_ON_ERROR).') !== null', 15);

            $this->amenerAuCentre($browser, $supprimerLaMesure);
            $browser->mouseover('nav');
            $this->assertOpacite($browser, [$supprimerLaMesure => 0.0], 'avant le survol de la mesure');
            $browser->driver->action()->moveToElement($browser->driver->findElement(WebDriverBy::xpath(
                '//button[starts-with(@aria-label, "Supprimer la mesure du ")]/ancestor::*[contains(concat(" ", normalize-space(@class), " "), " group ")][1]',
            )))->perform();
            $this->assertOpacite($browser, [$supprimerLaMesure => 1.0], 'au survol de la mesure');
            $browser->mouseover('nav');
            $this->assertOpacite($browser, [$supprimerLaMesure => 0.0], 'la souris partie de la mesure');
            $this->tabulerVers($browser, $supprimerLaMesure);
            $this->assertOpacite($browser, [$supprimerLaMesure => 1.0], 'au focus du clavier sur la mesure');
        });
    }

    /**
     * Sous 640 px, la cellule du nom prend toute la largeur de la carte : les
     * actions y restent sous le nom, même à la souris. Posées en haut à droite
     * de la cellule, comme dans la colonne de 200 px, elles couvraient le nom
     * au survol.
     */
    public function test_a_la_souris_dans_une_fenetre_etroite_les_actions_d_une_habitude_restent_sous_son_nom(): void
    {
        $compte = $this->compte('fenetre-etroite');
        $habitude = Habit::factory()->for($compte)->create(['name' => 'Gainage du matin, tenu sur toute la longueur de la planche']);

        $modifierLHabitude = 'button[aria-label="Modifier l\'habitude"]';
        $supprimerLHabitude = 'button[aria-label="Supprimer l\'habitude"]';

        $this->browse(function (Browser $browser) use ($compte, $habitude, $modifierLHabitude, $supprimerLHabitude): void {
            $browser->loginAs($compte)
                ->resize(500, 900)
                ->visit('/habits')
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                // Présent, pas visible : WebDriver tient le bouton transparent pour caché.
                ->waitUntil('document.querySelector('.json_encode($supprimerLHabitude, JSON_THROW_ON_ERROR).') !== null', 15);

            $this->assertSame(['souris' => true, 'tactile' => false], $browser->script(self::LIRE_LE_POINTEUR)[0]);

            // Sous 1 024 px, la barre de bureau est masquée : la souris attend sur le titre de l'en-tête mobile, collant.
            $this->amenerAuCentre($browser, $supprimerLHabitude);
            $browser->mouseover('header h1');
            $this->assertOpacite($browser, [$modifierLHabitude => 0.0, $supprimerLHabitude => 0.0], 'avant le survol de l’habitude, dans une fenêtre étroite');
            $browser->driver->action()->moveToElement($browser->driver->findElement(WebDriverBy::xpath(
                '//h3[normalize-space(.) = '.json_encode($habitude->name, JSON_THROW_ON_ERROR).']',
            )))->perform();
            $this->assertOpacite($browser, [$modifierLHabitude => 1.0, $supprimerLHabitude => 1.0], 'au survol de l’habitude, dans une fenêtre étroite');

            $this->assertFalse(
                $this->couvreLeNom($browser, $habitude->name, $supprimerLHabitude),
                'Au survol, dans une fenêtre étroite, les boutons de l’habitude couvrent son nom, ou le nom est introuvable.',
            );
        });
    }

    public function test_sur_une_tablette_tactile_les_boutons_se_voient_sans_survol(): void
    {
        $compte = $this->compte('tablette');
        $exercice = Exercise::factory()->for($compte)->create(['name' => 'Tirage tablette '.Str::random(6), 'category' => 'Dos']);
        $habitude = Habit::factory()->for($compte)->create(['name' => 'Gainage du matin']);

        $carte = '[dusk="exercise-card-'.$exercice->id.'"]';
        $modifier = '[dusk="edit-exercise-btn-desktop-'.$exercice->id.'"]';
        $supprimer = '[dusk="delete-exercise-btn-'.$exercice->id.'"]';
        $modifierLHabitude = 'button[aria-label="Modifier l\'habitude"]';
        $supprimerLHabitude = 'button[aria-label="Supprimer l\'habitude"]';

        try {
            $this->browse(function (Browser $browser) use ($compte, $carte, $modifier, $supprimer, $habitude, $modifierLHabitude, $supprimerLHabitude): void {
                $outils = new ChromeDevToolsDriver($browser->driver);
                $outils->execute('Emulation.setTouchEmulationEnabled', ['enabled' => true, 'maxTouchPoints' => 5]);

                $browser->loginAs($compte)
                    ->resize(1024, 768)
                    ->visit('/exercises')
                    ->disableAnimations()
                    ->waitFor('#main-content', 30)
                    ->waitFor($carte, 15);

                $this->assertSame(['souris' => false, 'tactile' => true], $browser->script(self::LIRE_LE_POINTEUR)[0]);

                // WebDriver tient un élément transparent pour caché : visibles, ils le sont pour lui aussi.
                $this->assertOpacite($browser, [$modifier => 1.0, $supprimer => 1.0], 'sur la tablette, dans la bibliothèque');
                $browser->assertVisible($modifier)->assertVisible($supprimer);

                $browser->visit('/habits')
                    ->disableAnimations()
                    ->waitFor('#main-content', 30)
                    ->waitFor($supprimerLHabitude, 15);

                $this->assertOpacite($browser, [$modifierLHabitude => 1.0, $supprimerLHabitude => 1.0], 'sur la tablette, dans les habitudes');
                $browser->assertVisible($modifierLHabitude)->assertVisible($supprimerLHabitude);

                // Visibles à demeure, ils ne se posent plus sur le nom de l'habitude.
                $this->assertFalse(
                    $this->couvreLeNom($browser, $habitude->name, $supprimerLHabitude),
                    'Les boutons de l’habitude couvrent son nom, ou le nom est introuvable.',
                );

                $outils->execute('Emulation.setTouchEmulationEnabled', ['enabled' => false]);
            });
        } finally {
            // Sans émulation tactile, Chrome n'a plus de pointeur du tout : le parcours suivant démarre le sien.
            static::closeAll();
        }
    }

    private function compte(string $appareil): User
    {
        return User::factory()->create([
            'email' => "boutons-au-survol-{$appareil}-".Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * L'opacité vue de chaque élément, attendue cinq secondes au plus :
     * `disableAnimations()` coupe les transitions, mais le survol et le focus
     * passent par le pilote.
     *
     * @param  array<string, float>  $attendues
     */
    private function assertOpacite(Browser $browser, array $attendues, string $quand): void
    {
        $limite = microtime(true) + 5;

        do {
            $lues = [];

            foreach (array_keys($attendues) as $selecteur) {
                $lues[$selecteur] = $this->opaciteVue($browser, $selecteur);
            }

            if ($lues === $attendues) {
                break;
            }

            usleep(100_000);
        } while (microtime(true) < $limite);

        $this->assertSame($attendues, $lues, "Opacités {$quand}.");
    }

    /**
     * Si le conteneur des actions d'une habitude recouvre son nom : `null`
     * quand l'un ou l'autre manque, ce qu'`assertFalse` refuse aussi.
     */
    private function couvreLeNom(Browser $browser, string $nom, string $bouton): ?bool
    {
        $couvre = $browser->script(sprintf(
            <<<'JS'
                const nom = [...document.querySelectorAll('h3')].find((titre) => titre.textContent.trim() === %s);
                const actions = document.querySelector(%s)?.parentElement ?? null;
                if (nom === undefined || actions === null) { return null; }
                const a = nom.getBoundingClientRect();
                const b = actions.getBoundingClientRect();
                return a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;
                JS,
            json_encode($nom, JSON_THROW_ON_ERROR),
            json_encode($bouton, JSON_THROW_ON_ERROR),
        ))[0] ?? null;

        return is_bool($couvre) ? $couvre : null;
    }

    /** Fait défiler la page jusqu'à l'élément : la bibliothèque montre aussi le catalogue commun. */
    private function amenerAuCentre(Browser $browser, string $selecteur): void
    {
        $browser->script('document.querySelector('.json_encode($selecteur, JSON_THROW_ON_ERROR).').scrollIntoView({ block: "center", behavior: "instant" });');
    }

    private function opaciteVue(Browser $browser, string $selecteur): ?float
    {
        $opacite = $browser->script(str_replace('SELECTEUR', json_encode($selecteur, JSON_THROW_ON_ERROR), self::OPACITE_VUE))[0] ?? null;

        return is_int($opacite) || is_float($opacite) ? (float) $opacite : null;
    }

    /** Amène le focus sur l'élément par une vraie touche Tab, depuis celui qui le précède. */
    private function tabulerVers(Browser $browser, string $selecteur): void
    {
        $this->assertTrue(
            $browser->script(str_replace('SELECTEUR', json_encode($selecteur, JSON_THROW_ON_ERROR), self::FOCUS_AVANT))[0] ?? false,
            "Rien ne précède {$selecteur} dans l'ordre de tabulation.",
        );

        $browser->driver->action()->sendKeys(null, WebDriverKeys::TAB)->perform();

        $this->assertTrue(
            $browser->script('return document.activeElement === document.querySelector('.json_encode($selecteur, JSON_THROW_ON_ERROR).');')[0] ?? false,
            "La touche Tab n'a pas amené le focus sur {$selecteur}.",
        );
    }
}
