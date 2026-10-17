<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\Supplement;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A page that scrolls sideways on a phone is broken in a way that is easy to
 * ship and hard to notice: the developer sees it at 1400px, the user sees the
 * right edge of every card cut off and the layout drifting under their thumb.
 *
 * WorkoutRowOverflowTest already guards one page. This guards the rest, which
 * is where the defect can actually appear unnoticed — nobody was measuring.
 *
 * 375px is the iPhone SE and the narrowest device this app targets. Chaque
 * page se mesure aussi à 640 px, où les mises en page `sm:` s'ouvrent à leur
 * plus étroit.
 *
 * Le bureau a son propre piège : la barre de navigation de bureau demande
 * quelque 1 000 px de large, et ce qu'on y ajoute la fait déborder. Elle se
 * mesure donc aussi, sur l'accueil, aux largeurs de
 * `largeursDeLaBarreDeBureau()`, et avec un nom de compte démesuré.
 */
class NoSidewaysScrollTest extends DuskTestCase
{
    /**
     * html and body both carry overflow-x: hidden, so the page can never
     * report a sideways scroll — it clips instead, and the content that
     * overflows is simply cut off. Measuring scrollWidth as it stands
     * therefore always passes. The clipping is lifted for the duration of the
     * measurement, which asks the question that matters: would this page
     * overflow if it were not being hidden?
     *
     * The blurred background blobs are 600px squares placed off-canvas on
     * purpose and rely on that clip. They carry no content and cannot be
     * reached, so they are not what this is looking for.
     *
     * A row of chips inside a horizontal scroller is meant to extend past the
     * viewport — that is the control, not a defect. Only content with no way
     * to be scrolled to counts.
     */
    private const string MESURE_DU_DEBORDEMENT = 'const style = document.createElement("style");'
        .'style.textContent = "html,body{overflow-x:visible !important}";'
        .'document.head.appendChild(style);'
        .'const d = document.documentElement;'
        .'const decorative = el => {'
        .'  const c = (el.className || "").toString();'
        .'  if (/liquid-bg|blur-\[/.test(c)) return true;'
        .'  const s = getComputedStyle(el);'
        .'  return s.pointerEvents === "none" && s.position === "absolute";'
        .'};'
        .'const inScroller = el => {'
        .'  for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {'
        .'    const o = getComputedStyle(p).overflowX;'
        .'    if (o === "auto" || o === "scroll") return true;'
        .'  }'
        .'  return false;'
        .'};'
        .'let widest = "", worst = d.clientWidth;'
        .'document.querySelectorAll("body *").forEach(el => {'
        .'  if (decorative(el) || inScroller(el)) return;'
        .'  const r = el.getBoundingClientRect();'
        .'  if (r.width > 0 && r.right > worst) { worst = r.right;'
        .'    widest = el.tagName.toLowerCase() + "." + (el.className || "").toString().slice(0, 90); }'
        .'});'
        .'const result = {scroll: Math.round(worst), client: d.clientWidth, widest};'
        .'style.remove();'
        .'return result;';

    /**
     * Quelle barre la page montre, et où se pose le bouton du compte dans la
     * barre de bureau : son bord droit, son haut et son bas, et ceux de la
     * barre. Les sélecteurs sont ceux de LesFenetresRestentDansLaZoneSureTest.
     */
    private const string LIRE_LA_BARRE = <<<'JS'
        const affiche = (element) => element !== null && getComputedStyle(element).display !== 'none';
        const barre = document.querySelector('nav.z-nav');
        const ouverte = affiche(barre);
        const compte = ouverte ? barre.querySelector('button[aria-haspopup]').getBoundingClientRect() : null;
        const ligne = ouverte ? barre.querySelector('.h-16').getBoundingClientRect() : null;
        return {
            barreDeBureau: ouverte,
            enteteMobile: affiche(document.querySelector('header.z-collant')),
            barreDuBas: affiche(document.querySelector('nav.glass-nav')),
            compte: compte === null ? null : { droite: compte.right, haut: compte.top, bas: compte.bottom },
            barre: ligne === null ? null : { haut: ligne.top, bas: ligne.bottom },
        };
        JS;

    /**
     * @return array<string, array{string}>
     */
    public static function mainPages(): array
    {
        return [
            'accueil' => ['dashboard'],
            'séances' => ['workouts.index'],
            'exercices' => ['exercises.index'],
            'objectifs' => ['goals.index'],
            'habitudes' => ['habits.index'],
            'compléments' => ['supplements.index'],
            'mesures' => ['body-measurements.index'],
            'mensurations' => ['body-parts.index'],
            'calendrier' => ['calendar.index'],
            'statistiques' => ['stats.index'],
            'journal' => ['daily-journals.index'],
            'modèles' => ['templates.index'],
            'outils' => ['tools.index'],
            '1RM' => ['tools.1rm'],
            'wilks' => ['tools.wilks'],
            'macros' => ['tools.macro-calculator'],
            'échauffement' => ['tools.warmup'],
            'hydratation' => ['tools.water.index'],
            'minuteur' => ['tools.interval-timer.index'],
            'jeûne' => ['tools.fasting.index'],
            'disques' => ['plates.index'],
            'profil' => ['profile.edit'],
        ];
    }

    /**
     * A page with nothing on it cannot overflow. Overflow comes from content —
     * a long exercise name, a goal title someone actually typed, a four-digit
     * volume — so an empty account would have made this test decorative.
     *
     * The strings are long on purpose, but not absurd: they are the length of
     * things people really enter.
     */
    private function userWithContent(): User
    {
        $user = User::factory()->create(['name' => 'Jean-Christophe de la Villemarqué']);

        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'name' => 'Développé couché incliné à la barre guidée prise large',
        ]);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Séance haut du corps — poussée lourde et accessoires',
            'started_at' => now()->subDays(2),
            'ended_at' => now()->subDays(2)->addHours(2),
        ]);

        WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

        Goal::factory()->create([
            'user_id' => $user->id,
            'type' => 'frequency',
            'exercise_id' => null,
            'title' => 'Atteindre 200 séances avant la fin de l\'année prochaine',
            'target_value' => 200,
        ]);

        Habit::factory()->create([
            'user_id' => $user->id,
            'name' => 'Boire trois litres d\'eau répartis sur la journée entière',
        ]);

        Supplement::factory()->create([
            'user_id' => $user->id,
            'name' => 'Créatine monohydrate micronisée Creapure',
            'brand' => 'Bulk Powders Performance Series',
        ]);

        BodyMeasurement::factory()->create(['user_id' => $user->id, 'measured_at' => now()->subDay()]);

        return $user;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mainPages')]
    public function test_the_page_does_not_scroll_sideways_on_the_narrowest_phone(string $routeName): void
    {
        $this->browse(function (Browser $browser) use ($routeName): void {
            $browser->loginAs($this->userWithContent())
                ->resize(375, 812)
                ->visit(route($routeName))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitForStableLayout();

            ['scroll' => $scroll, 'client' => $client, 'widest' => $widest] = $this->debordementDeLaPage($browser);

            $this->assertLessThanOrEqual(
                $client,
                $scroll,
                "La page {$routeName} déborde de ".($scroll - $client)."px. Élément le plus à droite : {$widest}"
            );
        });
    }

    /**
     * À 640 px, les mises en page `sm:` s'ouvrent sur la largeur la plus
     * étroite qu'elles connaîtront, ascenseur compris (625 px de page) : un
     * iPhone SE couché en a 667. La grille des habitudes y débordait de 36 px
     * jusqu'à 675 px de large, la colonne du dimanche coupée et les cases
     * décalées sous leurs jours : ses sept colonnes réservaient la largeur
     * de leur case à cocher, celles de l'en-tête non (#2057).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mainPages')]
    public function test_la_page_ne_defile_pas_de_cote_ou_s_ouvrent_les_mises_en_page_sm(string $routeName): void
    {
        $this->browse(function (Browser $browser) use ($routeName): void {
            $browser->loginAs($this->userWithContent())
                ->resize(640, 900)
                ->visit(route($routeName))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitForStableLayout();

            ['scroll' => $scroll, 'client' => $client, 'widest' => $widest] = $this->debordementDeLaPage($browser);

            $this->assertLessThanOrEqual(
                $client,
                $scroll,
                "À 640px, la page {$routeName} déborde de ".($scroll - $client)."px. Élément le plus à droite : {$widest}"
            );
        });
    }

    /**
     * Les largeurs de bureau où la page ne doit pas défiler de côté, et la
     * barre que chacune montre.
     *
     * La barre de bureau s'ouvre à partir de 1 024 px de large (variante
     * `bureau:` de app.css, #2057). Logo, six liens, cloche et menu du compte
     * lui demandent 971 px avec un compte « Test User », plus les 15 px de
     * l'ascenseur que Chrome montre ici ; un nom plus long s'abrège dans ce
     * qui reste. Ouverte dès 640 px, elle débordait jusqu'à 960 px avec le
     * nom de userWithContent(), menu du compte rogné, et ce menu passait sur
     * trois lignes, hors de la barre, jusqu'à 1 000 px. En deçà de 1 024 px,
     * l'en-tête mobile et la barre du bas la remplacent. 768 px est une
     * tablette debout, 1 080 un écran 1080p tourné en portrait, 1 100 la
     * largeur jusqu'où #2057 voyait le défaut (le nom long y passait sur deux
     * lignes, il s'y abrège ; il tient entier à partir de 1 160 px), 1 280
     * `xl`.
     *
     * @return array<string, array{int, bool}>
     */
    public static function largeursDeLaBarreDeBureau(): array
    {
        return [
            'sm, l’ancien seuil' => [640, false],
            'tablette debout' => [768, false],
            'fenêtre étroite' => [900, false],
            'juste sous le seuil' => [1000, false],
            'au seuil, tablette couchée' => [1024, true],
            '1080p en portrait' => [1080, true],
            'nom long abrégé, largeur citée par #2057' => [1100, true],
            'xl' => [1280, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('largeursDeLaBarreDeBureau')]
    public function test_la_barre_de_bureau_tient_dans_la_largeur_de_l_ecran(int $largeur, bool $barreDeBureau): void
    {
        $this->browse(function (Browser $browser) use ($largeur, $barreDeBureau): void {
            $browser->loginAs($this->userWithContent())
                ->resize($largeur, 900)
                ->visit(route('dashboard'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitForStableLayout();

            $this->assertLaBarreTient($browser, $largeur, $barreDeBureau);
        });
    }

    /**
     * Un nom de compte va jusqu'à 255 caractères, et un mot sans espace ne se
     * coupe nulle part. Le bouton du compte repliait le nom sur autant de
     * lignes qu'il fallait et sortait de la barre à toute largeur (138 px de
     * haut à 1 280 px pour un nom de 246 caractères), et ce nom-ci, qui finit
     * par 50 lettres sans espace, le poussait hors de la fenêtre (de 281 px à
     * 1 024 px, de 25 à 1 280). Il prend désormais la place qui reste et
     * s'abrège.
     *
     * @return array<string, array{int}>
     */
    public static function largeursDUnNomDemesure(): array
    {
        return [
            'au seuil' => [1024],
            'xl' => [1280],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('largeursDUnNomDemesure')]
    public function test_un_nom_de_compte_demesure_s_abrege_dans_la_barre_de_bureau(int $largeur): void
    {
        $user = User::factory()->create([
            'name' => str_repeat('Maximilien-Alexandre de La Rochefoucauld ', 5).str_repeat('x', 50),
        ]);

        $this->browse(function (Browser $browser) use ($user, $largeur): void {
            $browser->loginAs($user)
                ->resize($largeur, 900)
                ->visit(route('dashboard'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitForStableLayout();

            $this->assertLaBarreTient($browser, $largeur, true);
        });
    }

    /**
     * La page ne défile pas de côté, montre la barre attendue, et le bouton
     * du compte de la barre de bureau tient entier dans la fenêtre et dans
     * la hauteur de la barre.
     */
    private function assertLaBarreTient(Browser $browser, int $largeur, bool $barreDeBureau): void
    {
        ['scroll' => $scroll, 'client' => $client, 'widest' => $widest] = $this->debordementDeLaPage($browser);

        $this->assertLessThanOrEqual(
            $client,
            $scroll,
            "À {$largeur}px, l'accueil déborde de ".($scroll - $client)."px. Élément le plus à droite : {$widest}"
        );

        /** @var array{barreDeBureau: bool, enteteMobile: bool, barreDuBas: bool, compte: array{droite: float, haut: float, bas: float}|null, barre: array{haut: float, bas: float}|null} $barre */
        $barre = $browser->script(self::LIRE_LA_BARRE)[0];

        $this->assertSame(
            ['barreDeBureau' => $barreDeBureau, 'enteteMobile' => ! $barreDeBureau, 'barreDuBas' => ! $barreDeBureau],
            ['barreDeBureau' => $barre['barreDeBureau'], 'enteteMobile' => $barre['enteteMobile'], 'barreDuBas' => $barre['barreDuBas']],
            $barreDeBureau
                ? "À {$largeur}px, la barre de bureau remplace l'en-tête mobile et la barre du bas."
                : "À {$largeur}px, l'en-tête mobile et la barre du bas remplacent la barre de bureau, qui n'y tient pas."
        );

        if (! $barreDeBureau) {
            return;
        }

        $this->assertNotNull($barre['compte']);
        $this->assertNotNull($barre['barre']);
        $this->assertLessThanOrEqual($client, $barre['compte']['droite'], "À {$largeur}px, le menu du compte sort de la fenêtre.");
        $this->assertGreaterThanOrEqual($barre['barre']['haut'], $barre['compte']['haut'], "À {$largeur}px, le menu du compte dépasse en haut de la barre.");
        $this->assertLessThanOrEqual($barre['barre']['bas'], $barre['compte']['bas'], "À {$largeur}px, le menu du compte dépasse en bas de la barre.");
    }

    /**
     * Le bord droit le plus lointain de la page, sa largeur visible et
     * l'élément qui porte ce bord.
     *
     * @return array{scroll: int, client: int, widest: string}
     */
    private function debordementDeLaPage(Browser $browser): array
    {
        /** @var array{0: array{scroll: int, client: int, widest: string}} $measurement */
        $measurement = $browser->script(self::MESURE_DU_DEBORDEMENT);

        return $measurement[0];
    }
}
