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
 * 375px is the iPhone SE and the narrowest device this app targets.
 *
 * Le bureau a son propre piège : la barre de navigation s'ouvre dès 640 px et
 * tient tout juste dans sa largeur. Elle se mesure donc aussi, sur l'accueil,
 * aux largeurs de `largeursDeLaBarreDeBureau()`.
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
     * Les largeurs de bureau où la barre de navigation doit tenir.
     *
     * Elle s'ouvre dès 640 px de large sur un écran haut, dès 1 024 px sur
     * tout écran (variante `bureau:` de app.css). Logo et nom ensemble lui
     * demandaient 1 103 px de large avec un compte « Test User », 1 151 avec
     * le nom long de userWithContent(), plus les 15 px de l'ascenseur que
     * Chrome montre ici : en deçà, le menu du compte sortait de l'écran,
     * chevron compris.
     * Le logo seul en demande 947 avec ce nom. 1 080 px est la largeur d'un
     * écran 1080p tourné en portrait, entre `lg` et `xl` ; 1 280 px, la
     * largeur où elle montrait aussi le nom, avant que le logo ne s'y montre
     * seul à toute largeur.
     *
     * @return array<string, array{int}>
     */
    public static function largeursDeLaBarreDeBureau(): array
    {
        return [
            'logo seul, 1080p en portrait' => [1080],
            'à partir de xl' => [1280],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('largeursDeLaBarreDeBureau')]
    public function test_la_barre_de_bureau_tient_dans_la_largeur_de_l_ecran(int $largeur): void
    {
        $this->browse(function (Browser $browser) use ($largeur): void {
            $browser->loginAs($this->userWithContent())
                ->resize($largeur, 900)
                ->visit(route('dashboard'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitForStableLayout();

            ['scroll' => $scroll, 'client' => $client, 'widest' => $widest] = $this->debordementDeLaPage($browser);

            $this->assertLessThanOrEqual(
                $client,
                $scroll,
                "À {$largeur}px, l'accueil déborde de ".($scroll - $client)."px. Élément le plus à droite : {$widest}"
            );
        });
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
