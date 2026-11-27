<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
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
        const toast = document.querySelector('#colonne-des-toasts [role="alert"] > div');
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
                $browser->waitFor('#colonne-des-toasts [role="alert"] > div', 10);

                /** @var array{largeur: string, couleur: string, attendue: string} $lisere */
                $lisere = $browser->script(str_replace('__JETON__', $jeton, self::LIT_LE_LISERE))[0];

                $this->assertSame('6px', $lisere['largeur'], "Le liseré du toast « $type » n'a pas 6 px.");
                $this->assertSame($lisere['attendue'], $lisere['couleur'], "Le liseré du toast « $type » n'a pas la couleur de $jeton.");

                $browser->script(str_replace('__TYPE__', $type, 'document.querySelector("#app").__vue_app__.config.globalProperties.$page.props.flash.__TYPE__ = null;'));
                $browser->waitUntilMissing('#colonne-des-toasts [role="alert"]', 10);
            }
        });
    }

    public function test_le_toast_d_annulation_porte_le_meme_lisere_et_le_meme_verre_que_les_autres(): void
    {
        $compte = User::factory()->create(['email' => 'toast-annulation-'.time().random_int(0, 999).'@example.com', 'email_verified_at' => now()]);
        $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now(), 'ended_at' => null]);
        $ligne = WorkoutLine::factory()->create([
            'workout_id' => $seance->id,
            'exercise_id' => Exercise::factory()->create(['user_id' => $compte->id, 'type' => 'strength'])->id,
        ]);
        Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 50, 'reps' => 5, 'order' => 0]);

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            $browser->loginAs(User::findOrFail($compte->id))->resizeToIphone15()->visit('/workouts/'.$seance->id)
                ->disableAnimations()->waitFor('#main-content', 30)->waitFor('@weight-input-0-0', 15);

            $lit = <<<'JS'
                const panneau = document.querySelector('%s');
                const sonde = document.createElement('i');
                sonde.style.color = 'var(--color-accent-state)';
                document.body.appendChild(sonde);
                const attendue = getComputedStyle(sonde).color;
                sonde.remove();
                const style = getComputedStyle(panneau);

                return {
                    largeur: style.borderLeftWidth, couleur: style.borderLeftColor, attendue,
                    fond: style.backgroundColor, flou: style.backdropFilter, graisse: getComputedStyle(panneau.querySelector('p')).fontWeight,
                };
                JS;

            $browser->script('document.querySelector("#app").__vue_app__.config.globalProperties.$page.props.flash = { success: "Un message de test." };');
            $browser->waitFor('#colonne-des-toasts [role="alert"] > div', 10);
            /** @var array{largeur: string, couleur: string, attendue: string, fond: string, flou: string, graisse: string} $succes */
            $succes = $browser->script(sprintf($lit, '#colonne-des-toasts [role="alert"] > div'))[0];
            $browser->script('document.querySelector("#app").__vue_app__.config.globalProperties.$page.props.flash.success = null;');
            $browser->waitUntilMissing('#colonne-des-toasts [role="alert"]', 10);

            $browser->script('document.querySelector(\'[dusk="swipe-remove-set-0-0"]\').click();');
            $browser->waitFor('@annuler-la-suppression', 10);
            /** @var array{largeur: string, couleur: string, attendue: string, fond: string, flou: string, graisse: string} $annulation */
            $annulation = $browser->script(sprintf($lit, '[dusk="toast-suppression"] > div'))[0];

            $this->assertSame('6px', $annulation['largeur'], 'Le liseré du toast d\'annulation n\'a pas 6 px.');
            $this->assertSame($annulation['attendue'], $annulation['couleur'], 'Le liseré du toast d\'annulation n\'a pas la couleur de accent-state.');
            $this->assertSame($succes['fond'], $annulation['fond'], 'Le toast d\'annulation n\'a pas le verre épais des autres toasts.');
            $this->assertSame($succes['flou'], $annulation['flou'], 'Le toast d\'annulation n\'a pas le flou des autres toasts.');
            $this->assertSame($succes['graisse'], $annulation['graisse'], 'Le texte du toast d\'annulation n\'a pas la graisse des autres toasts.');
        });
    }
}
