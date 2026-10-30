<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Habit;
use App\Models\Supplement;
use App\Models\User;
use App\Models\Workout;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The project's own threshold is --spacing-touch: 44px, and its own technique
 * for reaching it without changing how a control looks is an absolutely
 * positioned ::before with a negative inset — documented in a comment on the
 * habit checkbox, and used nowhere else. Everything else took the size of its
 * icon: 18px to remove a warm-up step, 22px to edit or delete a habit, with
 * the edit and delete buttons a few pixels apart.
 *
 * A ::before does not show up in getBoundingClientRect, so measuring the box
 * would report the old size and prove nothing. elementFromPoint probes what a
 * thumb would actually hit.
 *
 * La sonde n'accepte que le contrôle ou ce qu'il contient (#2116). Elle
 * acceptait aussi tout ancêtre du contrôle : un point qui tombait sur l'en-tête
 * autour d'un lien de 40 px comptait pour touché, et le retour de l'en-tête
 * mobile du jeûne, parcouru à 375 × 812, passait. Le profil, où vit la seule
 * déconnexion d'un téléphone, et l'écran de séance, l'une des seize pages qui
 * ont un retour, sont parcourus aussi.
 */
class TouchTargetSizeTest extends DuskTestCase
{
    /**
     * La route, et de quoi bâtir ses paramètres pour le compte du parcours.
     *
     * @return array<string, array{0: string, 1?: \Closure(User): array<string, mixed>}>
     */
    public static function pagesWithIconControls(): array
    {
        return [
            'habitudes' => ['habits.index'],
            'compléments' => ['supplements.index'],
            'jeûne' => ['tools.fasting.index'],
            'minuteur' => ['tools.interval-timer.index'],
            'échauffement' => ['tools.warmup'],
            'profil' => ['profile.index'],
            'séance' => ['workouts.show', static fn (User $compte): array => ['workout' => Workout::factory()->for($compte)->create()]],
        ];
    }

    /**
     * @param  (\Closure(User): array<string, mixed>)|null  $parametres
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pagesWithIconControls')]
    public function test_every_icon_control_can_be_hit_with_a_thumb(string $routeName, ?\Closure $parametres = null): void
    {
        $this->browse(function (Browser $browser) use ($routeName, $parametres): void {
            $user = User::factory()->create();
            Habit::factory()->create(['user_id' => $user->id, 'name' => 'Boire trois litres d\'eau']);
            Supplement::factory()->create(['user_id' => $user->id, 'name' => 'Créatine monohydrate']);

            $browser->loginAs($user)
                ->resize(375, 812)
                ->visit(route($routeName, $parametres instanceof \Closure ? $parametres($user) : []))
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                // Icon glyphs fall back to wide text until the symbol font lands,
                // which changes what elementFromPoint returns for a frame or two.
                ->waitForStableLayout();

            /** @var array<int, string> $tooSmall */
            $tooSmall = $browser->script(
                'const MIN = 44, reach = [];'
                .'const hits = (el, x, y) => {'
                .'  const at = document.elementFromPoint(x, y);'
                // Le contrôle ou ce qu'il contient, jamais un ancêtre : l'en-tête
                // autour d'un lien de 40 px n'est pas le lien (#2116).
                .'  return at && (at === el || el.contains(at));'
                .'};'
                .'document.querySelectorAll("button, a[href]").forEach(el => {'
                .'  const r = el.getBoundingClientRect();'
                .'  if (r.width === 0 || r.top < 0 || r.bottom > innerHeight) return;'
                .'  const label = (el.textContent || "").replace(/\s+/g, "");'
                .'  const icon = el.querySelector(".material-symbols-outlined");'
                .'  if (!icon || label !== (icon.textContent || "").replace(/\s+/g, "")) return;'
                // Sondé à MIN/2 - 2 : à exactement 44px dans une colonne de 53, une
                // sonde à ±21 tombe sur la case voisine par arrondi sous-pixel.
                .'  const cx = r.left + r.width / 2, cy = r.top + r.height / 2, half = MIN / 2 - 2;'
                .'  const tall = hits(el, cx, cy - half) && hits(el, cx, cy + half);'
                .'  const wide = hits(el, cx - half, cy) && hits(el, cx + half, cy);'
                // Une boîte qui atteint déjà le seuil est conforme par définition. La
                // sonde existe pour les contrôles qui dépendent d'une zone élargie,
                // ou que quelque chose recouvre — pas pour arbitrer un arrondi
                // sous-pixel entre deux voisins qui font tous deux la bonne taille.
                .'  const boxIsBigEnough = r.width >= MIN - 0.5 && r.height >= MIN - 0.5;'
                .'  if (!boxIsBigEnough && (!tall || !wide)) {'
                .'    const cx2 = r.left + r.width / 2, cy2 = r.top + r.height / 2;'
                .'    const blocker = document.elementFromPoint(cx2, cy2 - (MIN / 2 - 1));'
                .'    const who = blocker ? blocker.tagName.toLowerCase() + "." + (blocker.className || "").toString().slice(0, 40) : "rien";'
                .'    reach.push((el.getAttribute("aria-label") || icon.textContent || "?").trim()'
                .'      + " [" + Math.round(r.width) + "x" + Math.round(r.height) + "] bloqué par " + who);'
                .'  }'
                .'});'
                .'return reach;'
            )[0];

            $this->assertSame(
                [],
                $tooSmall,
                "Sur {$routeName}, ces contrôles n'atteignent pas 44px : ".implode(' · ', (array) $tooSmall)
            );
        });
    }

    /**
     * Sous 1 024 px, « Déconnexion » du profil est le seul moyen de se
     * déconnecter : l'autre est dans le menu du compte, que seule la barre de
     * bureau porte. Un bouton à texte, que la sonde des icônes ne lit pas :
     * en `text-xs` sans rembourrage, il tenait en 16 px de haut (#2116).
     */
    public function test_la_deconnexion_du_profil_se_touche_au_pouce(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->loginAs(User::factory()->create())
                ->resize(375, 812)
                ->visit(route('profile.index'))
                ->disableAnimations()
                ->waitFor('[data-testid="logout-button"]', 30)
                ->waitForStableLayout();

            /** @var array{largeur: float, hauteur: float} $cadre */
            $cadre = $browser->script(
                'const r = document.querySelector(\'[data-testid="logout-button"]\').getBoundingClientRect();'
                .'return { largeur: r.width, hauteur: r.height };'
            )[0];

            $this->assertGreaterThanOrEqual(43.5, $cadre['hauteur'], 'La déconnexion du profil fait moins de 44 px de haut.');
            $this->assertGreaterThanOrEqual(43.5, $cadre['largeur'], 'La déconnexion du profil fait moins de 44 px de large.');
        });
    }
}
