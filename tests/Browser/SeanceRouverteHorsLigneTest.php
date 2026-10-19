<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ParcoursHorsLigne;

/**
 * Une série saisie sans réseau reste à l'écran quand la séance se rouvre, et
 * part une seule fois au retour du réseau (#2048, parties B et F).
 *
 * Avant, recharger la séance sans réseau montrait « Pas de réseau », et la
 * série saisie disparaissait de l'écran, la file restant intacte (constat b de
 * l'issue) ; Retour puis Avance la rendaient sans elle (constat e). Désormais,
 * le rechargement passe par le worker, qui sert la coquille ; elle démarre
 * l'application sur la copie de la séance (`demarrageHorsLigne.js`), et la
 * séance y superpose ce qui attend en file (`seanceEtFile.js`), marqué
 * `cloud_off`. Le réseau rendu, la file se vide une seule fois (une seule
 * ligne en base), puis la séance se recharge en entier et perd son bandeau.
 *
 * Le réseau de la page est coupé et public/build introuvable pendant la
 * coupure (`Tests\Support\ParcoursHorsLigne`) : le rechargement, Retour et
 * Avance n'ont que le precache et les copies. Le parcours exige un contexte
 * sûr : la CI sert 127.0.0.1 ; sous Sail, il se déclare sauté.
 */
class SeanceRouverteHorsLigneTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    /**
     * Si la file hors ligne garde la création de la série saisie, valeurs et
     * coche fondues dans la même entrée.
     */
    private const string LA_FILE_GARDE_LA_SERIE = <<<'SCRIPT'
        (() => {
            const file = JSON.parse(localStorage.getItem('offline_sync_queue') || '[]');
            const creations = file.filter((entree) => entree.method === 'post' && String(entree.url).endsWith('/api/v1/sets'));

            return file.length === 1
                && creations.length === 1
                && Number(creations[0].data?.weight) === 82.5
                && Number(creations[0].data?.reps) === 7
                && creations[0].data?.is_completed === true;
        })()
    SCRIPT;

    public function test_une_serie_saisie_sans_reseau_survit_au_rechargement_et_a_l_historique_puis_part_une_fois(): void
    {
        $jetons = ['seance' => Str::random(12), 'exercice' => Str::random(12)];
        $compte = User::factory()->create([
            'email' => 'seance-rouverte-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
        $seance = Workout::factory()->for($compte)->create([
            'name' => 'Seance '.$jetons['seance'],
            'started_at' => now()->subMinutes(20),
            'ended_at' => null,
        ]);
        $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Exercice '.$jetons['exercice'], 'type' => 'strength']);
        $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id, 'order' => 0]);
        Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 60, 'reps' => 5, 'is_completed' => false]);

        $cheminDeLaSeance = '/workouts/'.$seance->id;

        $this->browse(function (Browser $browser) use ($compte, $jetons, $cheminDeLaSeance, $ligne): void {
            $browser->loginAs($compte)
                ->visit('/dashboard')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);

            $browser->disableAnimations()
                ->waitFor('[dusk="active-workout-banner"]', 30)
                ->clickWhenSettled('[dusk="active-workout-banner"]')
                ->waitForLocation($cheminDeLaSeance, 15)
                ->waitFor('[dusk="weight-input-0-0"]', 15);

            $this->attendreLesCopies($browser, ['/dashboard', $cheminDeLaSeance]);

            $outils = $this->outilsDuNavigateur($browser);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser, $jetons, $cheminDeLaSeance): void {
                // Dans la séance ouverte : une série ajoutée, saisie, cochée. Elle part en file, marquée cloud_off.
                $browser->clickWhenSettled('[dusk="add-set-0"]')
                    ->waitFor('[dusk="weight-input-0-1"]', 15)
                    ->type('[dusk="weight-input-0-1"]', '82.5')
                    ->type('[dusk="reps-input-0-1"]', '7')
                    ->clickWhenSettled('[dusk="complete-set-0-1"]')
                    ->waitFor('[dusk="set-unsynced-0-1"]', 15)
                    ->waitUntil(self::LA_FILE_GARDE_LA_SERIE, 15, 'la file ne garde pas la série saisie et cochée en une seule entrée');

                // Le rechargement : la coquille, la copie de la séance, et la série superposée depuis la file.
                $browser->refresh();
                $this->attendreLaSerieEnAttente($browser, $cheminDeLaSeance, $jetons['exercice']);
                $browser->waitUntil($this->laCoquilleServiePour('reseau'), 15, 'le rechargement n’a pas ouvert la coquille')
                    ->waitFor('[dusk="bandeau-hors-ligne"]', 15);

                // Retour, puis Avance : la série est toujours là.
                $browser->back()
                    ->waitForLocation('/dashboard', 15)
                    ->waitFor('[dusk="active-workout-banner"]', 15);

                $browser->forward();
                $this->attendreLaSerieEnAttente($browser, $cheminDeLaSeance, $jetons['exercice']);

                $this->assertTrue($browser->script('return '.self::LA_FILE_GARDE_LA_SERIE.';')[0], 'la file a changé sans réseau');
            });

            // Le réseau rendu : la file se vide, une seule fois.
            $this->waitForDatabase(
                fn (): bool => Set::query()->where('workout_line_id', $ligne->id)->count() === 2,
                60,
                'la série saisie sans réseau n’a pas atteint la base',
                fn (): string => (string) json_encode(Set::query()->where('workout_line_id', $ligne->id)->get(['weight', 'reps', 'is_completed'])),
            );

            // Puis la séance se recharge en entier : le bandeau part, la série est celle du serveur.
            $browser->waitUntilMissing('[dusk="bandeau-hors-ligne"]', 90)
                ->assertPathIs($cheminDeLaSeance)
                ->waitUntilMissing('[dusk="set-unsynced-0-1"]', 30)
                ->assertValue('[dusk="weight-input-0-1"]', '82.5')
                ->assertValue('[dusk="reps-input-0-1"]', '7');

            $this->assertSame([], $browser->script("return JSON.parse(localStorage.getItem('offline_sync_queue') || '[]');")[0], 'la file garde encore une entrée');
        });

        $series = Set::query()->where('workout_line_id', $ligne->id)->orderBy('id')->get();

        $this->assertCount(2, $series, 'la série saisie sans réseau est arrivée plus d’une fois');
        $saisie = $series->last();
        $this->assertInstanceOf(Set::class, $saisie);
        $this->assertSame(82.5, (float) $saisie->weight);
        $this->assertSame(7, (int) $saisie->reps);
        $this->assertTrue((bool) $saisie->is_completed);
    }

    /**
     * Attend la séance à son adresse, avec la série saisie sans réseau : ses
     * valeurs, sa coche et sa marque `cloud_off`.
     */
    private function attendreLaSerieEnAttente(Browser $browser, string $chemin, string $exercice): void
    {
        $browser->waitForLocation($chemin, 15)
            ->waitForText($exercice, 15, true)
            ->waitFor('[dusk="set-unsynced-0-1"]', 15)
            ->assertValue('[dusk="weight-input-0-1"]', '82.5')
            ->assertValue('[dusk="reps-input-0-1"]', '7')
            ->assertAttribute('[dusk="complete-set-0-1"]', 'aria-label', 'Annuler la série')
            // La première série, enregistrée avant la coupure, n'attend rien.
            ->assertMissing('[dusk="set-unsynced-0-0"]')
            ->assertScript("document.querySelector('[dusk=\"set-unsynced-0-1\"] .material-symbols-outlined')?.textContent.trim()", 'cloud_off');
    }
}
