<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * AuthenticatedLayout renders the #header slot inside a `hidden bureau:block`
 * element, so any primary action placed only there silently disappears on
 * phones — the app's main target. Pages are expected to mirror the action into
 * #header-actions; Goals shipped without it and became impossible to use on
 * mobile. These tests pin that contract at the viewport sizes users actually
 * have.
 *
 * Depuis #2057, `bureau:` s'ouvre à 1 024 px de large : une tablette debout
 * perd l'en-tête de page comme un téléphone. Ce qu'il dit, et pas seulement
 * ce qu'il commande, se lit donc aussi dans la page.
 */
class MobileHeaderActionsTest extends DuskTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function mobileViewports(): array
    {
        return [
            'iphone mini' => ['resizeToIphoneMini'],
            'iphone 15' => ['resizeToIphone15'],
            'iphone max' => ['resizeToIphoneMax'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mobileViewports')]
    public function test_goal_creation_is_reachable_on_mobile(string $viewport): void
    {
        $this->browse(function (Browser $browser) use ($viewport): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->{$viewport}()
                ->visit(route('goals.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@create-goal-btn', 10)
                ->assertVisible('@create-goal-btn')
                ->click('@create-goal-btn')
                // Asserted structurally rather than by text: several labels are
                // uppercased in CSS, and WebDriver reports transformed text.
                ->waitFor('@goal-create-form', 10)
                ->assertVisible('@goal-create-form')
                ->assertNoConsoleExceptions();
        });
    }

    /**
     * Le formulaire de poids s'ouvre depuis le « + » de l'en-tête mobile, et ses
     * trois champs s'empilent en une colonne à 375 px. Vitest ne lit que des
     * noms de classe ; ici, c'est la mise en page que Chrome calcule.
     */
    public function test_weight_form_opens_from_the_plus_in_one_column_with_the_weight_focused(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->resizeToIphoneMini()
                ->visit(route('body-measurements.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('button[aria-label="Ajouter une mesure"]', 10)
                ->click('button[aria-label="Ajouter une mesure"]')
                ->waitFor('main form', 10);

            /** @var array{champs: list<array{haut: float, gauche: float, droite: float}>, poidsFocalise: bool, largeur: int} $lecture */
            $lecture = $browser->script(<<<'JS'
                const champs = [...document.querySelectorAll('main form input')].slice(0, 3).map((champ) => {
                    const boite = champ.getBoundingClientRect();

                    return { haut: boite.top, gauche: boite.left, droite: boite.right };
                });

                return {
                    champs,
                    poidsFocalise: document.activeElement === document.querySelector('main form input'),
                    largeur: document.documentElement.clientWidth,
                };
                JS)[0];

            $this->assertCount(3, $lecture['champs']);
            $this->assertTrue($lecture['poidsFocalise'], 'le focus doit être sur le champ du poids');

            foreach ($lecture['champs'] as $rang => $champ) {
                $this->assertLessThanOrEqual($lecture['largeur'], $champ['droite'], "le champ {$rang} déborde de l'écran");
                $this->assertGreaterThan(200, $champ['droite'] - $champ['gauche'], "le champ {$rang} est trop étroit pour une colonne unique");

                if ($rang > 0) {
                    $this->assertGreaterThan($lecture['champs'][$rang - 1]['haut'], $champ['haut'], "le champ {$rang} n'est pas sous le précédent");
                }
            }
        });
    }

    public function test_mobile_header_shows_the_page_title(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            // Without page-title the mobile bar renders empty and the user has no
            // indication of where they are, since the desktop heading is hidden.
            $browser->loginAs($user)
                ->resizeToIphone15()
                ->visit(route('goals.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                // Scoped to the sticky mobile bar's own <h1>: asserting against the
                // whole source would also match <Head title> and pass even when the
                // bar renders nothing. Compared case-insensitively because the
                // heading is uppercased in CSS and WebDriver returns rendered text.
                ->assertVisible('header.sticky h1')
                ->with('header.sticky h1', function (Browser $heading): void {
                    $this->assertSame(
                        'mes objectifs',
                        mb_strtolower(trim($heading->text('')))
                    );
                });
        });
    }

    public function test_desktop_still_exposes_the_full_header_action(): void
    {
        $this->browse(function (Browser $browser): void {
            $user = User::factory()->create();

            $browser->loginAs($user)
                ->resize(1280, 900)
                ->visit(route('goals.index'))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor('@create-goal-btn-desktop', 10)
                ->assertVisible('@create-goal-btn-desktop')
                ->click('@create-goal-btn-desktop')
                ->waitFor('@goal-create-form', 10)
                ->assertVisible('@goal-create-form');
        });
    }

    /**
     * Le compte des badges et la catégorie d'un exercice ne vivaient que dans
     * l'en-tête de page : sous 1 024 px, ils ne s'affichaient nulle part. Le
     * texte se lit en minuscules, WebDriver rendant les capitales de la CSS.
     *
     * @return array<string, array{0: string, 1: int, 2: int, 3: string}>
     */
    public static function informationsDeLEnTeteDePage(): array
    {
        return [
            'le compte des badges, tablette debout' => ['achievements.index', 768, 1024, '~\d+ / \d+\s+débloqués~u'],
            'le compte des badges, téléphone' => ['achievements.index', 390, 844, '~\d+ / \d+\s+débloqués~u'],
            'la catégorie de l’exercice, tablette debout' => ['exercises.show', 768, 1024, '~pectoraux • force~u'],
            'la catégorie de l’exercice, téléphone' => ['exercises.show', 390, 844, '~pectoraux • force~u'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('informationsDeLEnTeteDePage')]
    public function test_ce_que_dit_l_en_tete_de_page_se_lit_sans_la_barre_de_bureau(string $routeName, int $largeur, int $hauteur, string $attendu): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id, 'category' => 'Pectoraux', 'type' => 'strength']);
        $adresse = $routeName === 'exercises.show' ? route($routeName, $exercise) : route($routeName);

        $this->browse(function (Browser $browser) use ($user, $adresse, $largeur, $hauteur, $attendu): void {
            $browser->loginAs($user)
                ->resize($largeur, $hauteur)
                ->visit($adresse)
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->assertMissing('nav.z-nav');

            $this->assertMatchesRegularExpression($attendu, mb_strtolower($browser->text('')));
        });
    }
}
