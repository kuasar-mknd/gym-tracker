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
 * Un poids de trois chiffres doit se lire en entier dans son champ, sur le
 * plus petit iPhone, série validée ou non (#1788). La mesure est celle du
 * navigateur : un champ dont le contenu déborde a un scrollWidth plus grand
 * que sa largeur visible.
 */
class LesChiffresTiennentDansLeursChampsTest extends DuskTestCase
{
    use DatabaseTruncation;

    /**
     * Une valeur plausible par champ : un poids ou une distance porte une
     * décimale, un pourcentage et des répétitions tiennent en trois chiffres.
     * Remplir tout à « 142.5 » exigerait d'un champ de pourcentage une largeur
     * qu'aucune valeur réelle ne lui demande.
     */
    private const string REMPLIR = <<<'JS'
        document.querySelectorAll('input[type="number"]').forEach((champ) => {
            const nom = (champ.getAttribute('aria-label') || champ.getAttribute('dusk') || '').toLowerCase();
            const decimal = /poids|weight|kg|distance|km|charge|barre/.test(nom);
            champ.value = decimal ? '142.5' : '100';
            champ.dispatchEvent(new Event('input', { bubbles: true }));
            champ.dispatchEvent(new Event('change', { bubbles: true }));
        });
    JS;

    private const string MESURE = <<<'JS'
        return Array.from(document.querySelectorAll('input[type="number"]'))
            .filter((champ) => champ.offsetParent !== null)
            .map((champ) => ({
                id: champ.getAttribute('dusk') || champ.getAttribute('aria-label') || champ.id,
                deborde: champ.scrollWidth > champ.clientWidth,
                visible: champ.clientWidth,
                contenu: champ.scrollWidth,
            }))
            .filter((champ) => champ.deborde);
    JS;

    public function test_la_seance_et_les_outils_a_la_taille_du_plus_petit_iphone(): void
    {
        $user = User::factory()->create();
        $exercice = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Développé couché', 'type' => 'strength']);
        $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subMinutes(10)]);
        $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id, 'order' => 0]);
        foreach ([0, 1] as $ordre) {
            Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 142.5, 'reps' => 12, 'order' => $ordre, 'is_completed' => $ordre === 0]);
        }

        $this->browse(function (Browser $browser) use ($user, $seance): void {
            $browser->loginAs(User::findOrFail($user->id))->resizeToIphoneMini();

            $pages = [
                '/workouts/'.$seance->id => '#main-content',
                '/plates' => '#main-content',
                '/tools/1rm' => '#main-content',
                '/tools/warmup' => '#main-content',
            ];

            foreach ($pages as $url => $attente) {
                $browser->visit($url)->waitFor($attente, 30)->waitForStableLayout();

                $browser->script(self::REMPLIR);
                $browser->waitForStableLayout();

                $debordements = $browser->script(self::MESURE)[0];
                $this->assertSame([], $debordements, sprintf('Des champs débordent sur %s : %s', $url, json_encode($debordements, JSON_UNESCAPED_UNICODE)));
            }
        });
    }
}
