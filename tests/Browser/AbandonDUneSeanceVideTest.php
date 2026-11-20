<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Une séance vide s'abandonne : « Abandonner », la question, puis Séances (#2434).
 *
 * Le bouton n'existe que sur une séance sans exercice. La confirmation
 * supprime la séance par `workouts.destroy` ; la page Séances ne la montre
 * plus, et l'accessoire de la séance en cours disparaît avec elle. Le parcours
 * attend la base avant de finir : l'écran seul prouverait la redirection, pas
 * la suppression.
 */
class AbandonDUneSeanceVideTest extends DuskTestCase
{
    private function compte(): User
    {
        return User::factory()->create([
            'email' => 'abandon-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
    }

    public function test_une_seance_vide_s_abandonne_apres_confirmation(): void
    {
        $compte = $this->compte();
        $seance = Workout::factory()->for($compte)->create([
            'name' => 'Séance à abandonner',
            'started_at' => now()->subMinutes(2),
            'ended_at' => null,
        ]);

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            // La séance se lit dans la liste avant l'abandon : l'absence qui suit ne prouve rien sans ce témoin.
            $lienDeLaSeance = 'a[href$="/workouts/'.$seance->id.'"]';

            $browser->loginAs($compte)
                ->resizeToIphone15()
                ->visit('/workouts')
                ->disableAnimations()
                ->waitFor($lienDeLaSeance, 30)
                ->visit("/workouts/{$seance->id}")
                ->waitFor('[dusk="abandonner-la-seance"]', 30)
                ->assertMissing('[dusk="confirm-dialog-confirm"]')
                ->clickWhenSettled('[dusk="abandonner-la-seance"]')
                ->waitFor('[dusk="confirm-dialog-confirm"]', 15)
                // Le titre passe en capitales par le CSS : le texte se lit dans le DOM.
                ->assertScript("document.querySelector('dialog[open] h2').textContent.trim()", 'Abandonner cette séance ?');

            // Renoncer ne supprime rien.
            $browser->click('[dusk="confirm-dialog-cancel"]')
                ->waitUntilMissing('[dusk="confirm-dialog-confirm"]', 15);
            $this->assertNotNull(Workout::query()->find($seance->id));

            $browser->clickWhenSettled('[dusk="abandonner-la-seance"]')
                ->waitFor('[dusk="confirm-dialog-confirm"]', 15)
                ->click('[dusk="confirm-dialog-confirm"]')
                ->waitForLocation('/workouts', 30);

            $browser->waitUsing(
                15,
                100,
                static fn (): bool => Workout::query()->whereKey($seance->id)->doesntExist(),
                'La séance abandonnée est toujours en base (%s s).',
            );

            $browser->waitFor('#main-content', 15)
                ->assertMissing($lienDeLaSeance)
                ->assertMissing('[dusk="active-workout-banner"]');
        });
    }

    /**
     * La barre d'outils de la séance et l'accessoire flottent à 6 px du menu du
     * bas, à toute largeur : le menu change de hauteur sous 20 rem, et aucun test
     * unitaire ne mesure la mise en page (#2434).
     */
    public function test_la_barre_et_l_accessoire_flottent_a_six_pixels_du_menu(): void
    {
        $compte = $this->compte();
        $seance = Workout::factory()->for($compte)->create(['started_at' => now()->subMinutes(2), 'ended_at' => null]);
        $ecart = fn (string $dusk): string => "(() => { document.documentElement.style.scrollbarWidth = 'none'; return Math.round(document.querySelector('nav.glass-nav').getBoundingClientRect().top - document.querySelector('[dusk=\"{$dusk}\"]').getBoundingClientRect().bottom) })()";

        $this->browse(function (Browser $browser) use ($compte, $seance, $ecart): void {
            $browser->loginAs($compte)
                ->resizeToIphone15()
                ->visit("/workouts/{$seance->id}")
                ->disableAnimations()
                ->waitFor('[dusk="barre-d-outils-de-la-seance"]', 30);

            foreach ([[393, 852], [375, 812], [320, 700]] as [$largeur, $hauteur]) {
                $browser->resize($largeur, $hauteur)->pause(300);
                $this->assertSame(6, $browser->script($ecart('barre-d-outils-de-la-seance'))[0], "barre à {$largeur} px");
            }

            $browser->resize(375, 812)
                ->visit('/dashboard')
                ->waitFor('[dusk="active-workout-banner"]', 30);

            foreach ([[375, 812], [320, 700]] as [$largeur, $hauteur]) {
                $browser->resize($largeur, $hauteur)->pause(300);
                $this->assertSame(6, $browser->script($ecart('active-workout-banner'))[0], "accessoire à {$largeur} px");
            }
        });
    }

    public function test_une_seance_qui_a_un_exercice_ne_propose_pas_d_abandon(): void
    {
        $compte = $this->compte();
        $exercice = Exercise::factory()->for($compte)->create(['name' => 'Squat '.Str::random(5)]);
        $seance = Workout::factory()->for($compte)->create(['started_at' => now()->subMinutes(2), 'ended_at' => null]);
        WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

        $this->browse(function (Browser $browser) use ($compte, $seance): void {
            $browser->loginAs($compte)
                ->resizeToIphone15()
                ->visit("/workouts/{$seance->id}")
                ->disableAnimations()
                ->waitFor('[dusk="enregistrer-comme-modele"]', 30)
                ->assertMissing('[dusk="abandonner-la-seance"]');
        });
    }
}
