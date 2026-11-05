<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Une icône est une ligature : un mot (« notifications », « bar_chart_4_bars »)
 * que la police Material Symbols dessine en un seul glyphe, large d'un em.
 * Tant que la police n'est pas arrivée, le mot se compose en toutes lettres
 * dans la police de secours, et il prenait toute sa longueur : sur l'accueil,
 * au téléphone, la barre du bas et l'en-tête débordaient sur le côté.
 * `PageScrollTest` tombait au hasard quand il mesurait l'accueil dans cet
 * instant-là, et la personne voyait le nom des icônes s'afficher puis la page
 * se remettre en place.
 *
 * Le parcours rend cet instant certain au lieu de l'attendre : il rend aux
 * icônes la police de secours, comme avant le chargement, puis mesure. Il lit
 * aussi le mode d'affichage de la police : en `swap`, le nom de l'icône
 * s'écrirait à l'écran pendant le chargement ; en `block`, l'icône reste vide.
 */
class IconesAvantLeurPoliceTest extends DuskTestCase
{
    /**
     * La police de secours à la place de celle des icônes, puis, pour chaque
     * icône affichée, sa largeur et sa taille de police, et le débordement de
     * la page. Le style posé est retiré avant de rendre la mesure.
     */
    private const string MESURE_SANS_LA_POLICE = <<<'JS'
        const style = document.createElement('style');
        style.textContent = '.material-symbols-outlined { font-family: serif !important; }';
        document.head.appendChild(style);
        const icones = [...document.querySelectorAll('.material-symbols-outlined')]
            .map((icone) => ({
                nom: icone.textContent.trim(),
                largeur: icone.getBoundingClientRect().width,
                taille: parseFloat(getComputedStyle(icone).fontSize),
            }))
            .filter((icone) => icone.largeur > 0);
        const racine = document.documentElement;
        const mesure = {
            icones,
            tropLarges: icones.filter((icone) => icone.largeur > icone.taille + 0.5),
            largeurDeLaPage: racine.scrollWidth,
            largeurDeLEcran: racine.clientWidth,
        };
        style.remove();
        return mesure;
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
    public function test_une_icone_tient_dans_son_carre_avant_que_sa_police_n_arrive(string $routeName): void
    {
        $this->browse(function (Browser $browser) use ($routeName): void {
            $browser->loginAs(User::factory()->create())
                ->resizeToIphone15()
                ->visit(route($routeName))
                ->waitFor('#main-content', 30);

            /** @var array{icones: list<array{nom: string, largeur: float, taille: float}>, tropLarges: list<array{nom: string, largeur: float, taille: float}>, largeurDeLaPage: int, largeurDeLEcran: int} $mesure */
            $mesure = $browser->script(self::MESURE_SANS_LA_POLICE)[0];

            $this->assertNotEmpty($mesure['icones'], "La page {$routeName} doit afficher des icônes pour que la mesure prouve quelque chose.");

            $this->assertSame(
                [],
                $mesure['tropLarges'],
                "Sur {$routeName}, avant sa police, une icône doit tenir dans son carré d'un em, au lieu de prendre la longueur de son nom."
            );

            $this->assertLessThanOrEqual(
                $mesure['largeurDeLEcran'],
                $mesure['largeurDeLaPage'],
                "Sur {$routeName}, la page ne doit pas déborder sur le côté avant l'arrivée de la police des icônes."
            );
        });
    }

    public function test_la_police_des_icones_reste_invisible_pendant_son_chargement(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->loginAs(User::factory()->create())
                ->resizeToIphone15()
                ->visit(route('dashboard'))
                ->waitFor('#main-content', 30);

            /** @var list<string> $affichages */
            $affichages = $browser->script(
                'return [...document.fonts]'
                .'.filter((police) => police.family.replace(/["\']/g, "") === "Material Symbols Outlined")'
                .'.map((police) => police.display);'
            )[0];

            $this->assertSame(['block'], $affichages, "La police des icônes s'affiche en « block » : le nom d'une icône ne s'écrit jamais à l'écran.");
        });
    }
}
