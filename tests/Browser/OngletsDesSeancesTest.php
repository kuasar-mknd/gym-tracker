<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Models\WorkoutTemplate;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Sur téléphone, Historique, Modèles et Calendrier montrent la même rangée en
 * haut (le segment des séances et la cloche) : passer de l'une à l'autre ne la
 * fait pas bouger, et l'onglet de la page n'est pas un lien. Le bureau garde
 * ses boutons d'en-tête, sans cette rangée.
 */
class OngletsDesSeancesTest extends DuskTestCase
{
    use DatabaseTruncation;

    private const string LIT = <<<'JS'
        // Une barre de défilement de bureau (15 px) rétrécit la page longue seule : un téléphone n'en a pas.
        document.documentElement.style.scrollbarWidth = 'none';

        const boite = (element) => {
            const r = element.getBoundingClientRect();

            return [r.x, r.y, r.width, r.height].map((n) => Math.round(n * 100) / 100);
        };
        const segment = document.querySelector('#main-content nav[aria-label="Séances"]');
        const courants = segment === null ? [] : [...segment.querySelectorAll('[aria-current="page"]')];
        const visibles = (liens) => [...liens].filter((lien) => lien.getClientRects().length > 0);

        return {
            present: segment !== null,
            segment: segment === null ? null : boite(segment),
            rangee: segment === null ? null : boite(segment.parentElement),
            actifs: courants.map((element) => [element.tagName, element.textContent.trim()]),
            liens: segment === null ? [] : [...segment.querySelectorAll('a')].map((a) => [a.textContent.trim(), new URL(a.href).pathname]),
            cloches: visibles(document.querySelectorAll('a[href$="/notifications"]')).length,
            fleches: visibles(document.querySelectorAll('a[aria-label="Retour"]')).length,
            debordeLaPage: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        };
        JS;

    public function test_la_rangee_des_seances_ne_bouge_pas_d_une_page_a_l_autre_et_l_onglet_actif_n_est_pas_un_lien(): void
    {
        $compte = User::factory()->create([
            'email' => 'onglets-'.time().random_int(0, 999).'@example.com',
            'email_verified_at' => now(),
        ]);
        WorkoutTemplate::factory()->create(['user_id' => $compte->id, 'name' => 'Push']);

        $pages = [
            '/workouts' => ['Historique', [['Modèles', '/templates'], ['Calendrier', '/calendar']]],
            '/templates' => ['Modèles', [['Historique', '/workouts'], ['Calendrier', '/calendar']]],
            '/calendar' => ['Calendrier', [['Historique', '/workouts'], ['Modèles', '/templates']]],
        ];

        $this->browse(function (Browser $browser) use ($compte, $pages): void {
            $browser->loginAs(User::findOrFail($compte->id));

            try {
                foreach ([320, 393] as $largeur) {
                    $browser->resize($largeur, 852);
                    $boites = [];

                    foreach ($pages as $adresse => [$actif, $liens]) {
                        $browser->visit($adresse)->disableAnimations()->waitFor('#main-content nav[aria-label="Séances"]', 30)->waitForStableLayout();

                        /** @var array{present: bool, segment: list<float>, rangee: list<float>, actifs: list<list<string>>, liens: list<list<string>>, cloches: int, fleches: int, debordeLaPage: bool} $page */
                        $page = $browser->script(self::LIT)[0];

                        $this->assertSame([['SPAN', $actif]], $page['actifs'], "À $largeur px, $adresse : l'onglet actif n'est pas un span aria-current.");
                        $this->assertSame($liens, $page['liens'], "À $largeur px, $adresse : les deux autres onglets ne sont pas les bons liens.");
                        $this->assertSame(1, $page['cloches'], "À $largeur px, $adresse : la cloche doit se montrer une fois.");
                        $this->assertSame(0, $page['fleches'], "À $largeur px, $adresse : la flèche de retour ne se montre pas sur téléphone.");
                        $this->assertFalse($page['debordeLaPage'], "À $largeur px, $adresse : la page défile de côté.");

                        $boites[$adresse] = [$page['segment'], $page['rangee']];
                    }

                    $this->assertSame($boites['/workouts'], $boites['/templates'], "À $largeur px, la rangée bouge entre Historique et Modèles.");
                    $this->assertSame($boites['/workouts'], $boites['/calendar'], "À $largeur px, la rangée bouge entre Historique et Calendrier.");
                }
            } finally {
                $browser->resize(393, 852);
            }
        });
    }

    public function test_au_bureau_les_modeles_et_le_calendrier_gardent_leur_retour_sans_la_rangee(): void
    {
        $compte = User::factory()->create([
            'email' => 'onglets-bureau-'.time().random_int(0, 999).'@example.com',
            'email_verified_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs(User::findOrFail($compte->id));

            try {
                $browser->resize(1280, 900);

                foreach (['/templates', '/calendar'] as $adresse) {
                    $browser->visit($adresse)->disableAnimations()->waitFor('#main-content', 30)->waitForStableLayout();

                    $this->assertSame(
                        [false, true],
                        $browser->script("return [document.querySelector('[dusk=\"onglets-des-seances\"]').getClientRects().length > 0, document.querySelector('[dusk=\"retour-de-bureau\"]').getClientRects().length > 0];")[0],
                        "Au bureau, $adresse montre la rangée ou perd son retour.",
                    );
                }
            } finally {
                $browser->resize(393, 852);
            }
        });
    }
}
