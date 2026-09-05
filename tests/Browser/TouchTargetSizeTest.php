<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Habit;
use App\Models\Supplement;
use App\Models\User;
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
 */
class TouchTargetSizeTest extends DuskTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function pagesWithIconControls(): array
    {
        return [
            'habitudes' => ['habits.index'],
            'compléments' => ['supplements.index'],
            'jeûne' => ['tools.fasting.index'],
            'minuteur' => ['tools.interval-timer.index'],
            'échauffement' => ['tools.warmup'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pagesWithIconControls')]
    public function test_every_icon_control_can_be_hit_with_a_thumb(string $routeName): void
    {
        $this->browse(function (Browser $browser) use ($routeName): void {
            $user = User::factory()->create();
            Habit::factory()->create(['user_id' => $user->id, 'name' => 'Boire trois litres d\'eau']);
            Supplement::factory()->create(['user_id' => $user->id, 'name' => 'Créatine monohydrate']);

            $browser->loginAs($user)
                ->resize(375, 812)
                ->visit(route($routeName))
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
                .'  return at && (at === el || el.contains(at) || at.contains(el));'
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
}
