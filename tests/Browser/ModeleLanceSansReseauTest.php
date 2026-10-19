<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ParcoursHorsLigne;

/**
 * Lancer un modèle réseau coupé, depuis la copie de la page des modèles, puis
 * voir sa séance arriver au serveur avec ses exercices et ses séries (#2049).
 *
 * « Lancer cette séance » faisait une visite (`templates.execute`) qui ne
 * disait sans réseau que « Il faut le réseau pour démarrer une séance ». La
 * page des modèles est gardée pour la salle, avec le contenu de chaque modèle
 * et les clés que le serveur dérive (`lancements`) : sans réseau, la séance,
 * ses lignes et ses séries entrent en file d'un seul coup, sous ces clés, et
 * la séance s'ouvre aussitôt avec elles. Un poids changé se fond dans
 * l'entrée de sa série. Le réseau rendu, la file crée la séance au nom du
 * modèle, ses deux lignes et ses quatre séries, chacune sous sa clé dérivée.
 *
 * Le réseau de la page est coupé et public/build introuvable pendant la
 * coupure (`Tests\Support\ParcoursHorsLigne`) : la page des modèles, ouverte
 * par la tuile « Mes programmes » de l'accueil (au téléphone, la liste des
 * séances ne montre pas son lien « Modèles », réservé à l'écran large), et la
 * séance viennent du precache, des copies et de la file. Le
 * parcours exige un contexte sûr : la CI sert 127.0.0.1 ; sous Sail, il se
 * déclare sauté.
 */
class ModeleLanceSansReseauTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    /** La file, telle que le stockage la garde : chemin, clé et charge de chaque entrée. */
    private const string LA_FILE = <<<'SCRIPT'
        return JSON.parse(localStorage.getItem('offline_sync_queue') || '[]').map((entree) => ({
            chemin: new URL(entree.url, location.href).pathname,
            cle: entree.headers?.['Idempotency-Key'] ?? null,
            data: entree.data ?? null,
            tentee: entree.tentee === true,
        }));
    SCRIPT;

    public function test_un_modele_lance_sans_reseau_depuis_sa_copie_cree_au_retour_du_reseau_sa_seance_ses_lignes_et_ses_series_sous_les_cles_du_serveur(): void
    {
        $jeton = Str::random(12);
        $compte = User::factory()->create([
            'email' => 'modele-lance-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
        $squat = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Squat '.$jeton, 'type' => 'strength']);
        $tirage = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Tirage '.$jeton, 'type' => 'strength']);
        $modele = WorkoutTemplate::factory()->create(['user_id' => $compte->id, 'name' => 'Modele '.$jeton]);

        foreach ([[$squat, [[5, 100], [5, 120]]], [$tirage, [[8, 80], [8, 82.5]]]] as $rang => [$exercice, $series]) {
            $ligne = WorkoutTemplateLine::factory()->create([
                'workout_template_id' => $modele->id,
                'exercise_id' => $exercice->id,
                'order' => $rang,
            ]);

            foreach ($series as $rangDeLaSerie => [$repetitions, $poids]) {
                WorkoutTemplateSet::factory()->create([
                    'workout_template_line_id' => $ligne->id,
                    'reps' => $repetitions,
                    'weight' => $poids,
                    'is_warmup' => false,
                    'order' => $rangDeLaSerie,
                ]);
            }
        }

        $this->browse(function (Browser $browser) use ($compte, $modele, $jeton, $squat, $tirage): void {
            $browser->loginAs($compte)
                ->visit('/dashboard')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);

            // La page des modèles, ouverte en ligne : sa copie s'écrit, avec de quoi lancer le modèle.
            $browser->disableAnimations()
                ->visit('/templates')
                ->waitFor('[dusk="execute-template-'.$modele->id.'"]', 30);
            $this->attendreLesCopies($browser, ['/dashboard', '/templates']);

            // Puis l'accueil, en ligne : au téléphone, c'est sa tuile « Mes programmes » qui ouvre les modèles.
            $browser->clickWhenSettled('[dusk="nav-dashboard"]')
                ->waitForLocation('/dashboard', 15)
                ->waitFor('main a[href$="/templates"]', 15);
            $this->noterLesMessages($browser);

            $outils = $this->outilsDuNavigateur($browser);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser, $modele, $jeton): void {
                // « Mes programmes » : la page des modèles, de sa copie.
                $browser->clickWhenSettled('main a[href$="/templates"]')
                    ->waitForLocation('/templates', 15)
                    ->waitFor('[dusk="bandeau-hors-ligne"]', 15)
                    ->waitFor('[dusk="execute-template-'.$modele->id.'"]', 15);

                // « Lancer » reste ouvert sur la copie ; la séance s'ouvre aussitôt, ses exercices et ses séries avec elle.
                $browser->assertScript('document.querySelector('.json_encode('[dusk="execute-template-'.$modele->id.'"]').').disabled', false)
                    ->clickWhenSettled('[dusk="execute-template-'.$modele->id.'"]')
                    ->waitUntil(
                        "location.pathname.startsWith('/workouts/en-attente/') && document.querySelector('[dusk=\"weight-input-1-1\"]') !== null",
                        15,
                        'la séance du modèle ne s’est pas ouverte : '.json_encode($this->messagesSansReseau($browser), JSON_UNESCAPED_UNICODE),
                    )
                    ->waitForText('Squat '.$jeton, 15, true)
                    ->waitForText('Tirage '.$jeton, 15, true)
                    ->assertValue('[dusk="weight-input-0-0"]', '100')
                    ->assertValue('[dusk="weight-input-0-1"]', '120')
                    ->assertValue('[dusk="weight-input-1-0"]', '80')
                    ->assertValue('[dusk="weight-input-1-1"]', '82.5')
                    ->assertValue('[dusk="reps-input-1-1"]', '8')
                    ->assertMissing('[dusk="weight-input-2-0"]')
                    ->assertMissing('[dusk="weight-input-0-2"]');

                $this->assertSame([], $this->messagesSansReseau($browser), 'lancer le modèle a dit quelque chose du réseau');

                // Le poids de la dernière série change : il se fond dans l'entrée de sa série.
                $browser->clear('[dusk="weight-input-1-1"]')
                    ->type('[dusk="weight-input-1-1"]', '85')
                    ->waitUntil(
                        "JSON.parse(localStorage.getItem('offline_sync_queue') || '[]').some((entree) => entree.headers?.['Idempotency-Key'] === 'modele-serie:1:1' && Number(entree.data?.weight) === 85)",
                        15,
                        'le poids changé ne s’est pas fondu dans l’entrée de sa série',
                    )
                    // Le champ quitté, comme on touche ailleurs : une séance ne change pas de page sous un champ en saisie.
                    ->clickWhenSettled('[dusk="etat-de-la-seance-en-attente"]');

                /** @var list<array{chemin: string, cle: string|null, data: array<string, mixed>, tentee: bool}> $file */
                $file = $browser->script(self::LA_FILE)[0];
                $this->assertSame(
                    [
                        '/api/v1/workouts',
                        '/api/v1/workout-lines',
                        '/api/v1/sets',
                        '/api/v1/sets',
                        '/api/v1/workout-lines',
                        '/api/v1/sets',
                        '/api/v1/sets',
                    ],
                    array_column($file, 'chemin'),
                );
                $this->assertSame([false], array_values(array_unique(array_column($file, 'tentee'))), 'une écriture partie d’une copie a été tentée');
                $this->assertSame('Modele '.$jeton, $file[0]['data']['name'] ?? null);
                $this->assertArrayNotHasKey('template_id', $file[0]['data']);
            });

            // Le réseau rendu : la séance au nom du modèle, puis chaque ligne et chaque série.
            $this->waitForDatabase(
                fn (): bool => Set::query()->whereHas('workoutLine.workout', fn ($seance) => $seance->where('user_id', $compte->id))->count() === 4,
                90,
                'la séance du modèle n’est pas arrivée entière au serveur',
                fn (): string => (string) json_encode(Workout::query()->where('user_id', $compte->id)->with('workoutLines.sets')->get()),
            );

            $seance = Workout::query()->where('user_id', $compte->id)->sole();

            $browser->waitForLocation('/workouts/'.$seance->id, 60)
                ->waitForText('Tirage '.$jeton, 15, true)
                ->waitUntilMissing('[dusk="etat-de-la-seance-en-attente"]', 15)
                ->assertValue('[dusk="weight-input-1-1"]', '85');

            $this->assertSame([], $browser->script("return JSON.parse(localStorage.getItem('offline_sync_queue') || '[]');")[0], 'la file garde encore une entrée');
            $this->assertSame([], $browser->script("return JSON.parse(localStorage.getItem('offline_sync_failed') || '[]');")[0], 'une écriture du modèle a été refusée');

            $this->assertSame('Modele '.$jeton, $seance->name);
            $this->assertNull($seance->ended_at);

            $lignes = WorkoutLine::query()->where('workout_id', $seance->id)->orderBy('order')->get();
            $this->assertSame([$squat->id, $tirage->id], $lignes->pluck('exercise_id')->all());
            $this->assertSame(
                ['modele-ligne:0:'.$squat->id, 'modele-ligne:1:'.$tirage->id],
                $lignes->pluck('idempotency_key')->all(),
            );

            $series = Set::query()->whereIn('workout_line_id', $lignes->pluck('id'))->orderBy('workout_line_id')->orderBy('id')->get();
            $this->assertSame(
                ['modele-serie:0:0', 'modele-serie:0:1', 'modele-serie:1:0', 'modele-serie:1:1'],
                $series->pluck('idempotency_key')->all(),
            );
            $this->assertSame(
                [[5, 100.0], [5, 120.0], [8, 80.0], [8, 85.0]],
                $series->map(fn (Set $serie): array => [(int) $serie->reps, (float) $serie->weight])->all(),
            );
            $this->assertSame([false], array_values(array_unique($series->map(fn (Set $serie): bool => (bool) $serie->is_completed)->all())));
        });

        $this->assertSame(1, Workout::query()->where('user_id', $compte->id)->count(), 'le modèle a ouvert plus d’une séance');
    }
}
