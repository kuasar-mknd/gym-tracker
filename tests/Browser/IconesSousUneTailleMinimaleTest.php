<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Chrome laisse fixer une taille de police minimale (Paramètres, Apparence,
 * Personnaliser les polices). Une icône plus petite y est dessinée à ce
 * minimum, mais une longueur en em se calcule toujours sur sa taille
 * d'origine : la boîte d'un em qu'a d'abord posée #2193 (`width: 1em`,
 * `overflow-x: clip`) était plus étroite que le glyphe, et coupait « edit »,
 * « delete », « close » ou « add », écrites de 12 à 18 px.
 *
 * Le parcours règle ce minimum à 20 px dans le profil du navigateur, puis,
 * police chargée, exige que le texte de chaque icône, mesuré par une plage,
 * tienne dans sa boîte. Il vérifie d'abord que le réglage a pris, et que la
 * page montre des icônes agrandies par lui : sans elles, la mesure ne
 * prouverait rien.
 */
class IconesSousUneTailleMinimaleTest extends DuskTestCase
{
    private const int TAILLE_MINIMALE = 20;

    /**
     * Pour chaque icône affichée : sa boîte, son texte (une plage) et un em de
     * sa taille d'origine, lu sur une règle posée dans l'icône puis retirée.
     * Et la taille que le navigateur donne à un texte de 10 px.
     */
    private const string MESURE = <<<'JS'
        const sonde = document.createElement('span');
        sonde.style.fontSize = '10px';
        document.body.appendChild(sonde);
        const tailleDUnTexteDeDixPixels = parseFloat(getComputedStyle(sonde).fontSize);
        sonde.remove();
        const icones = [...document.querySelectorAll('.material-symbols-outlined')]
            .filter((icone) => icone.getBoundingClientRect().width > 0)
            .map((icone) => {
                const texte = document.createRange();
                texte.selectNodeContents(icone);
                const largeurDuTexte = texte.getBoundingClientRect().width;
                const regle = document.createElement('span');
                regle.style.cssText = 'display: inline-block; width: 1em; height: 0';
                icone.appendChild(regle);
                const unEm = regle.getBoundingClientRect().width;
                regle.remove();
                return { nom: icone.textContent.trim(), boite: icone.getBoundingClientRect().width, texte: largeurDuTexte, unEm };
            });
        return {
            tailleDUnTexteDeDixPixels,
            icones: icones.length,
            agrandies: icones.filter((icone) => icone.texte > icone.unEm + 0.5).length,
            coupees: icones.filter((icone) => icone.texte > icone.boite + 0.5),
        };
        JS;

    #[\Override]
    protected function capacitesDuNavigateur(): DesiredCapabilities
    {
        $capacites = parent::capacitesDuNavigateur();
        $options = $capacites->getCapability(ChromeOptions::CAPABILITY);

        if (! $options instanceof ChromeOptions) {
            $this->fail('Les capacités du navigateur ne portent pas d’options de Chrome.');
        }

        $options->setExperimentalOption('prefs', ['webkit.webprefs.minimum_font_size' => self::TAILLE_MINIMALE]);

        return $capacites->setCapability(ChromeOptions::CAPABILITY, $options);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return [
            'bibliothèque' => ['exercises.index'],
            'modèles' => ['templates.index'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_une_icone_agrandie_par_la_taille_minimale_n_est_pas_coupee(string $routeName): void
    {
        $compte = User::factory()->create();
        Exercise::factory()->for($compte)->create(['name' => 'Rowing minimum '.Str::random(6), 'category' => 'Dos']);

        $this->browse(function (Browser $browser) use ($compte, $routeName): void {
            $browser->loginAs($compte)
                ->visit(route($routeName))
                ->waitFor('#main-content', 30)
                ->waitUntil('document.fonts.status === "loaded"', 15);

            /** @var array{tailleDUnTexteDeDixPixels: float, icones: int, agrandies: int, coupees: list<array{nom: string, boite: float, texte: float, unEm: float}>} $mesure */
            $mesure = $browser->script(self::MESURE)[0];

            $this->assertEqualsWithDelta(
                self::TAILLE_MINIMALE,
                $mesure['tailleDUnTexteDeDixPixels'],
                0.01,
                'Le navigateur doit appliquer la taille de police minimale du profil, sans quoi le parcours ne mesure rien.'
            );
            $this->assertGreaterThan(0, $mesure['agrandies'], "La page {$routeName} doit montrer des icônes agrandies par la taille minimale (sur {$mesure['icones']}) pour que la mesure prouve quelque chose.");

            $this->assertSame(
                [],
                $mesure['coupees'],
                "Sur {$routeName}, une icône agrandie par la taille de police minimale doit tenir dans sa boîte, sans rien perdre de son dessin."
            );
        });
    }
}
