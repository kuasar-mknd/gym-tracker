<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Une icône est une ligature : un mot (« notifications », « bar_chart_4_bars »)
 * que la police Material Symbols dessine en un seul glyphe, large d'un em.
 * Tant que la police n'est pas arrivée, le mot se compose dans la police
 * suivante de la pile, et il prenait toute sa longueur : sur l'accueil, au
 * téléphone, la barre du bas et l'en-tête débordaient sur le côté.
 * `PageScrollTest` tombait au hasard quand il mesurait l'accueil dans cet
 * instant-là, et la personne voyait le nom des icônes s'afficher puis la page
 * se remettre en place.
 *
 * Le parcours rend cet instant certain au lieu de l'attendre : il retire la
 * police des icônes de leur pile, comme avant son arrivée ou après son échec,
 * puis mesure ce que la suite de la pile écrit : rien, dans un carré d'un em.
 * Il le mesure aussi à la première icône posée dans la page, avant toute mise
 * en page : une police de repli qui ne serait pas encore chargée à cet
 * instant ne servirait pas (relevé dans Chromium : il compose alors le nom
 * dans la police par défaut, invisible pendant la période de blocage, en
 * toutes lettres après).
 */
class IconesAvantLeurPoliceTest extends DuskTestCase
{
    /**
     * Une fonction qui pose la pile des icônes sans Material Symbols, puis
     * relève, pour chaque icône affichée, la largeur de sa boîte, celle de son
     * texte (une plage) et sa taille de police, et le débordement de la page.
     * Une pile réduite à Material Symbols laisse la police par défaut
     * (`initial`), comme le navigateur. L'état des polices de la suite est lu
     * avant la mesure, et le style posé est retiré avant de la rendre.
     */
    private const string MESURER_SANS_LA_POLICE = <<<'JS'
        () => {
            const sansGuillemets = (famille) => famille.trim().replace(/["']/g, '');
            const suite = getComputedStyle(document.querySelector('.material-symbols-outlined')).fontFamily
                .split(',')
                .map(sansGuillemets)
                .filter((famille) => famille !== 'Material Symbols Outlined');
            const etatsDeLaSuite = [...document.fonts]
                .filter((police) => suite.includes(sansGuillemets(police.family)))
                .map((police) => police.status);
            const style = document.createElement('style');
            style.textContent = `.material-symbols-outlined { font-family: ${suite.length > 0 ? suite.map((famille) => `'${famille}'`).join(', ') : 'initial'} !important; }`;
            document.head.appendChild(style);
            const icones = [...document.querySelectorAll('.material-symbols-outlined')]
                .filter((icone) => icone.getBoundingClientRect().width > 0)
                .map((icone) => {
                    const texte = document.createRange();
                    texte.selectNodeContents(icone);
                    return {
                        nom: icone.textContent.trim(),
                        largeur: icone.getBoundingClientRect().width,
                        texte: texte.getBoundingClientRect().width,
                        taille: parseFloat(getComputedStyle(icone).fontSize),
                    };
                });
            const racine = document.documentElement;
            const mesure = {
                suite,
                etatsDeLaSuite,
                icones,
                ecrites: icones.filter((icone) => icone.texte > 0.5),
                horsDeLeurCarre: icones.filter((icone) => Math.abs(icone.largeur - icone.taille) > 0.5),
                largeurDeLaPage: racine.scrollWidth,
                largeurDeLEcran: racine.clientWidth,
            };
            style.remove();
            return mesure;
        }
        JS;

    /**
     * Posé avant tout script de la page, après `const mesurer = …;`. À la
     * première icône insérée, avant toute mise en page, l'état des polices de
     * la suite de sa pile ; puis, à la première icône affichée, la mesure.
     */
    private const string GUET_DE_LA_PREMIERE_ICONE = <<<'JS'
        new MutationObserver((mutations, guet) => {
            const icones = [...document.querySelectorAll('.material-symbols-outlined')];
            if (icones.length === 0) {
                return;
            }
            if (window.__premiereIcone === undefined) {
                const sansGuillemets = (famille) => famille.trim().replace(/["']/g, '');
                const suite = getComputedStyle(icones[0]).fontFamily
                    .split(',')
                    .map(sansGuillemets)
                    .filter((famille) => famille !== 'Material Symbols Outlined');
                window.__premiereIcone = {
                    suite,
                    etats: [...document.fonts]
                        .filter((police) => suite.includes(sansGuillemets(police.family)))
                        .map((police) => police.status),
                };
            }
            if (icones.every((icone) => icone.getBoundingClientRect().width === 0)) {
                return;
            }
            guet.disconnect();
            window.__premiereMesure = mesurer();
        }).observe(document, { childList: true, subtree: true });
        JS;

    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return [
            'accueil' => ['dashboard'],
            'séances' => ['workouts.index'],
            'profil' => ['profile.edit'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_avant_sa_police_une_icone_n_ecrit_pas_son_nom_et_garde_son_carre(string $routeName): void
    {
        $this->browse(function (Browser $browser) use ($routeName): void {
            $browser->loginAs(User::factory()->create())
                ->resizeToIphone15()
                ->visit(route($routeName))
                ->waitFor('#main-content', 30);

            /** @var array{suite: list<string>, etatsDeLaSuite: list<string>, icones: list<array{nom: string, largeur: float, texte: float, taille: float}>, ecrites: list<array{nom: string, largeur: float, texte: float, taille: float}>, horsDeLeurCarre: list<array{nom: string, largeur: float, texte: float, taille: float}>, largeurDeLaPage: int, largeurDeLEcran: int} $mesure */
            $mesure = $browser->script('return ('.self::MESURER_SANS_LA_POLICE.')();')[0];

            $this->assertNotEmpty($mesure['icones'], "La page {$routeName} doit afficher des icônes pour que la mesure prouve quelque chose.");

            $this->assertSame(
                [],
                $mesure['ecrites'],
                "Sur {$routeName}, avant sa police, une icône ne doit rien écrire : la suite de sa pile (".implode(', ', $mesure['suite']).') a écrit son nom.'
            );

            $this->assertSame(
                [],
                $mesure['horsDeLeurCarre'],
                "Sur {$routeName}, avant sa police, une icône doit garder son carré d'un em, pour que rien ne bouge quand la police arrive."
            );

            $this->assertLessThanOrEqual(
                $mesure['largeurDeLEcran'],
                $mesure['largeurDeLaPage'],
                "Sur {$routeName}, la page ne doit pas déborder sur le côté avant l'arrivée de la police des icônes."
            );
        });
    }

    public function test_la_police_de_repli_est_prete_des_la_premiere_icone(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->loginAs(User::factory()->create())->resizeToIphone15();

            $outils = new ChromeDevToolsDriver($browser->driver);
            /** @var array{identifier: string} $guet */
            $guet = $outils->execute('Page.addScriptToEvaluateOnNewDocument', [
                'source' => 'const mesurer = '.self::MESURER_SANS_LA_POLICE.';'.self::GUET_DE_LA_PREMIERE_ICONE,
            ]);

            try {
                $browser->visit(route('dashboard'))
                    ->waitFor('#main-content', 30)
                    ->waitUntil('window.__premiereMesure !== undefined', 15);
            } finally {
                $outils->execute('Page.removeScriptToEvaluateOnNewDocument', ['identifier' => $guet['identifier']]);
            }

            /** @var array{premiereIcone: array{suite: list<string>, etats: list<string>}, mesure: array{icones: list<array{nom: string, largeur: float, texte: float, taille: float}>, ecrites: list<array{nom: string, largeur: float, texte: float, taille: float}>}} $releve */
            $releve = $browser->script('return { premiereIcone: window.__premiereIcone, mesure: window.__premiereMesure };')[0];
            $premiereIcone = $releve['premiereIcone'];
            $pile = (string) json_encode($premiereIcone, JSON_UNESCAPED_UNICODE);

            $this->assertNotEmpty($premiereIcone['suite'], "La pile des icônes doit porter une police de repli après Material Symbols : {$pile}");
            $this->assertSame(
                ['loaded'],
                $premiereIcone['etats'],
                "La police de repli des icônes doit être chargée avant la première icône : déclarée en CSS, même en data:, elle ne se charge qu'à son premier usage, et la première mise en page écrit le nom dans la police par défaut. {$pile}"
            );
            $this->assertNotEmpty($releve['mesure']['icones'], 'La première mesure doit porter sur des icônes affichées.');
            $this->assertSame(
                [],
                $releve['mesure']['ecrites'],
                "À la première icône affichée, avant sa police, aucune icône ne doit écrire son nom : {$pile}"
            );

            /** @var list<string> $affichages */
            $affichages = $browser->script(
                'return [...document.fonts]'
                .'.filter((police) => police.family.replace(/["\']/g, "") === "Material Symbols Outlined")'
                .'.map((police) => police.display);'
            )[0];

            $this->assertSame(['block'], $affichages, "Material Symbols s'affiche en « block » : si la police de repli manquait, le nom d'une icône resterait au moins invisible pendant la période de blocage.");
        });
    }
}
