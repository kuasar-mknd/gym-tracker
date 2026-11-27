<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Le toast d'état porte un liseré de 6 px à gauche, de la couleur de son
 * jeton. `verre-epais` pose sa bordure de 1 px après les utilitaires de
 * bordure dans le CSS compilé : sans suffixe important, elle les écrasait.
 */
class ToastsDuLayoutTest extends DuskTestCase
{
    use DatabaseTruncation;

    private const string LIT_LE_LISERE = <<<'JS'
        const toast = document.querySelector('.z-toast[role="alert"] > div');
        const sonde = document.createElement('i');
        sonde.style.color = 'var(--color-__JETON__)';
        document.body.appendChild(sonde);
        const attendue = getComputedStyle(sonde).color;
        sonde.remove();
        const style = getComputedStyle(toast);

        return { largeur: style.borderLeftWidth, couleur: style.borderLeftColor, attendue };
        JS;

    public function test_le_lisere_d_etat_du_toast_n_est_pas_ecrase_par_le_verre(): void
    {
        $compte = User::factory()->create(['email' => 'toast-lisere-'.time().random_int(0, 999).'@example.com', 'email_verified_at' => now()]);

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs(User::findOrFail($compte->id))->visit('/profile/edit')->disableAnimations()->waitFor('#main-content', 30);

            foreach (['success' => 'accent-state', 'error' => 'accent-danger'] as $type => $jeton) {
                $browser->script(str_replace('__TYPE__', $type, <<<'JS'
                    const page = document.querySelector('#app').__vue_app__.config.globalProperties.$page;
                    page.props.flash = { ...page.props.flash, __TYPE__: 'Un message de test.' };
                    JS));
                $browser->waitFor('.z-toast[role="alert"] > div', 10);

                /** @var array{largeur: string, couleur: string, attendue: string} $lisere */
                $lisere = $browser->script(str_replace('__JETON__', $jeton, self::LIT_LE_LISERE))[0];

                $this->assertSame('6px', $lisere['largeur'], "Le liseré du toast « $type » n'a pas 6 px.");
                $this->assertSame($lisere['attendue'], $lisere['couleur'], "Le liseré du toast « $type » n'a pas la couleur de $jeton.");

                $browser->script(str_replace('__TYPE__', $type, 'document.querySelector("#app").__vue_app__.config.globalProperties.$page.props.flash.__TYPE__ = null;'));
                $browser->waitUntilMissing('.z-toast[role="alert"]', 10);
            }
        });
    }
}
