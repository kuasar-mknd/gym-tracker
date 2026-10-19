<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ParcoursHorsLigne;

/**
 * Démarrer une séance, la remplir, la rouvrir à froid et la terminer, réseau
 * coupé, puis la voir arriver une fois au serveur (#2049).
 *
 * « Séance » disait sans réseau qu'il fallait le réseau (#2048, partie A). La
 * création passe désormais par la file, sous une clé tirée par l'appareil
 * (`Idempotency-Key`) et l'heure du geste : la séance s'ouvre aussitôt à
 * l'adresse de sa clé (`/workouts/en-attente/{clé}`), construite depuis
 * l'entrée de sa création ; ses exercices et ses séries attendent derrière
 * elle (`{ enAttenteDe }`) ; rechargée, la coquille la remonte depuis la file ;
 * « Terminer » met la fin en file à part, la dernière, à l'heure du geste. Le
 * réseau rendu, la file crée la séance, sa ligne, sa série, puis la clôt, et
 * la page passe à la séance réelle.
 *
 * Le serveur reste la vérité : une séance ouverte entre-temps depuis un autre
 * appareil fait refuser la création (409 `seance-deja-en-cours`), que le layout
 * annonce une fois avec ce qu'elle emporte, et la page passe en lecture seule.
 *
 * Le réseau de la page est coupé et public/build introuvable pendant la
 * coupure (`Tests\Support\ParcoursHorsLigne`) : ce qui s'ouvre vient du
 * precache, des copies et de la file. Le parcours exige un contexte sûr : la
 * CI sert 127.0.0.1 ; sous Sail, il se déclare sauté.
 */
class SeanceDemarreeSansReseauTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    /**
     * La file, telle que le stockage la garde : chaque entrée en une ligne,
     * méthode, chemin et charge.
     */
    private const string LA_FILE = <<<'SCRIPT'
        return JSON.parse(localStorage.getItem('offline_sync_queue') || '[]').map((entree) => ({
            methode: entree.method,
            chemin: new URL(entree.url, location.href).pathname,
            cle: entree.headers?.['Idempotency-Key'] ?? null,
            data: entree.data ?? null,
        }));
    SCRIPT;

    /**
     * Si la file garde la séance, sa ligne et sa série saisie et cochée,
     * valeurs fondues dans l'entrée de la série.
     */
    private const string LA_FILE_GARDE_LA_SEANCE = <<<'SCRIPT'
        (() => {
            const file = JSON.parse(localStorage.getItem('offline_sync_queue') || '[]');
            const chemins = file.map((entree) => new URL(entree.url, location.href).pathname);
            const serie = file.find((entree) => new URL(entree.url, location.href).pathname === '/api/v1/sets');

            return JSON.stringify(chemins) === JSON.stringify(['/api/v1/workouts', '/api/v1/workout-lines', '/api/v1/sets'])
                && Number(serie?.data?.weight) === 82.5
                && Number(serie?.data?.reps) === 5
                && serie?.data?.is_completed === true;
        })()
    SCRIPT;

    public function test_une_seance_demarree_sans_reseau_s_ouvre_se_rouvre_a_froid_se_termine_a_l_heure_du_geste_puis_part_une_fois(): void
    {
        [$compte, $exercice, $jeton] = $this->unCompteEtSonExercice('seance-demarree');

        $this->browse(function (Browser $browser) use ($compte, $exercice, $jeton): void {
            $outils = $this->ouvrirLAccueil($browser, $compte);

            /** @var array{cle: string, fin: string, avant: int, apres: int} $geste */
            $geste = ['cle' => '', 'fin' => '', 'avant' => 0, 'apres' => 0];

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser, $exercice, $jeton, &$geste): void {
                // « Séance » : la séance s'ouvre aussitôt, à l'adresse de sa clé, sans rien dire du réseau.
                $geste['cle'] = $this->demarrerSansReseau($browser);

                $this->ajouterLaSerie($browser, $exercice, $jeton);

                // Rechargée à froid : la coquille remonte la séance depuis la file, sa série saisie et cochée.
                $browser->refresh()
                    ->waitUntil($this->laCoquilleServiePour('reseau'), 15, 'le rechargement n’a pas ouvert la coquille')
                    ->waitForLocation('/workouts/en-attente/'.$geste['cle'], 15)
                    ->waitForText($jeton, 15, true)
                    ->waitFor('[dusk="weight-input-0-0"]', 15)
                    ->assertValue('[dusk="weight-input-0-0"]', '82.5')
                    ->assertValue('[dusk="reps-input-0-0"]', '5')
                    ->assertAttribute('[dusk="complete-set-0-0"]', 'aria-label', 'Annuler la série')
                    ->waitFor('[dusk="etat-de-la-seance-en-attente"]', 15);

                // « Terminer », à l'heure du geste, lue dans le navigateur autour du « Confirmer ».
                $browser->clickWhenSettled('[dusk="finish-workout-mobile"]')
                    ->waitFor('dialog[open] [dusk="confirm-finish-button"]', 15);
                $geste['avant'] = $this->maintenantDansLeNavigateur($browser);
                $browser->script("document.querySelector('dialog[open] [dusk=\"confirm-finish-button\"]').click();");
                $geste['apres'] = $this->maintenantDansLeNavigateur($browser);

                $browser->waitUntil(
                    "JSON.parse(localStorage.getItem('offline_sync_queue') || '[]').some((entree) => String(entree.url).endsWith('/api/v1/workouts/finish'))",
                    15,
                    'la fin de la séance n’est pas entrée en file',
                )->waitUntilMissing('dialog[open]', 15);

                $file = $this->laFile($browser);
                $this->assertSame(
                    ['/api/v1/workouts', '/api/v1/workout-lines', '/api/v1/sets', '/api/v1/workouts/finish'],
                    array_column($file, 'chemin'),
                    'la fin n’est pas la dernière de la file',
                );
                $fin = $file[3]['data']['ended_at'] ?? null;
                $this->assertIsString($fin);
                $geste['fin'] = $fin;
                $this->assertSame($geste['cle'], $file[0]['cle']);

                $instant = (int) round(Date::parse($fin)->getPreciseTimestamp(3));
                $this->assertGreaterThanOrEqual($geste['avant'], $instant, 'la fin a été prise avant le geste');
                $this->assertLessThanOrEqual($geste['apres'], $instant, 'la fin a été prise après le geste');

                // La séance se montre close, sans réseau : plus rien ne s'y ajoute.
                $browser->waitUntil($this->laPageDit('Séance terminée sur cet appareil'), 15, 'la séance ne se montre pas close')
                    ->assertMissing('[dusk="finish-workout-mobile"]')
                    ->assertMissing('[dusk="add-set-0"]');
            });

            // Le réseau rendu : la séance, sa ligne, sa série, puis sa fin, une seule fois.
            $this->waitForDatabase(
                fn (): bool => Workout::query()->where('user_id', $compte->id)->whereNotNull('ended_at')->exists(),
                90,
                'la séance démarrée sans réseau n’a pas été close au serveur',
                fn (): string => (string) json_encode(Workout::query()->where('user_id', $compte->id)->get(['id', 'idempotency_key', 'ended_at'])),
            );

            $seance = Workout::query()->where('user_id', $compte->id)->sole();

            // La page passe à la séance réelle, close.
            $browser->waitForLocation('/workouts/'.$seance->id, 60)
                ->waitForText($jeton, 15, true)
                ->assertMissing('[dusk="finish-workout-mobile"]');

            $this->assertSame([], $browser->script("return JSON.parse(localStorage.getItem('offline_sync_queue') || '[]');")[0], 'la file garde encore une entrée');

            $this->assertSame($geste['cle'], $seance->idempotency_key);
            $this->assertNotNull($seance->ended_at);
            $this->assertSame(
                Date::parse($geste['fin'])->getTimestamp(),
                $seance->ended_at->getTimestamp(),
                'la séance n’est pas close à l’heure du geste',
            );
        });

        $seances = Workout::query()->where('user_id', $compte->id)->get();
        $this->assertCount(1, $seances, 'la séance démarrée sans réseau est arrivée plus d’une fois');

        $lignes = WorkoutLine::query()->where('workout_id', $seances->sole()->id)->get();
        $this->assertCount(1, $lignes);
        $this->assertSame($exercice->id, $lignes->sole()->exercise_id);

        $serie = Set::query()->where('workout_line_id', $lignes->sole()->id)->sole();
        $this->assertSame(82.5, (float) $serie->weight);
        $this->assertSame(5, (int) $serie->reps);
        $this->assertTrue((bool) $serie->is_completed);
    }

    public function test_une_seance_demarree_sans_reseau_que_le_serveur_refuse_le_dit_n_emporte_rien_et_passe_en_lecture_seule(): void
    {
        [$compte, $exercice, $jeton] = $this->unCompteEtSonExercice('seance-refusee');

        $this->browse(function (Browser $browser) use ($compte, $exercice, $jeton): void {
            $outils = $this->ouvrirLAccueil($browser, $compte);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser, $compte, $exercice, $jeton): void {
                $this->demarrerSansReseau($browser);
                $this->ajouterLaSerie($browser, $exercice, $jeton);

                // Pendant la coupure, une séance s'ouvre depuis un autre appareil.
                Workout::factory()->for($compte)->create([
                    'name' => 'Autre appareil',
                    'started_at' => now()->subMinutes(5),
                    'ended_at' => null,
                ]);
            });

            // Le réseau rendu : la création est refusée, et le layout le dit une fois, avec ce qu'elle emporte.
            $browser->waitUntil(
                $this->laPageDit('2 modifications de cette séance ne partiront pas.'),
                90,
                'le refus de la séance n’a pas été annoncé',
            )->waitUntil(
                $this->laPageDit("La séance démarrée sans réseau n'a pas été enregistrée : une autre séance était déjà en cours."),
                15,
                'la séance ne dit pas qu’elle a été refusée',
            );

            // La page passe en lecture seule : ni série, ni exercice, ni fin.
            $browser->assertPathBeginsWith('/workouts/en-attente/')
                ->assertMissing('[dusk="add-set-0"]')
                ->assertMissing('[dusk="add-exercise-existing"]')
                ->assertMissing('[dusk="finish-workout-mobile"]')
                ->assertPresent('[dusk="seance-en-attente-accueil"]');

            $this->assertSame([], $browser->script("return JSON.parse(localStorage.getItem('offline_sync_queue') || '[]');")[0], 'la file garde encore une entrée');
        });

        // Rien d'autre n'est créé : la séance de l'autre appareil, seule, sans ligne.
        $seance = Workout::query()->where('user_id', $compte->id)->sole();
        $this->assertSame('Autre appareil', $seance->name);
        $this->assertSame(0, WorkoutLine::query()->where('workout_id', $seance->id)->count());
        $this->assertSame(0, WorkoutLine::query()->where('exercise_id', $exercice->id)->count());
    }

    /**
     * La file, telle que le stockage la garde.
     *
     * @return list<array{methode: string, chemin: string, cle: string|null, data: array<string, mixed>|null}>
     */
    private function laFile(Browser $browser): array
    {
        /** @var list<array{methode: string, chemin: string, cle: string|null, data: array<string, mixed>|null}> $file */
        $file = $browser->script(self::LA_FILE)[0];

        return $file;
    }

    /**
     * L'heure du navigateur, en millisecondes : celle qu'il donne au geste.
     */
    private function maintenantDansLeNavigateur(Browser $browser): int
    {
        $maintenant = $browser->script('return Date.now();')[0];
        $this->assertIsInt($maintenant);

        return $maintenant;
    }

    /**
     * Un compte vérifié et un exercice de force à lui, nommé d'un jeton tiré au
     * hasard : la bibliothèque le montre, la séance le nomme.
     *
     * @return array{0: User, 1: Exercise, 2: string}
     */
    private function unCompteEtSonExercice(string $prefixe): array
    {
        $jeton = Str::random(12);
        $compte = User::factory()->create([
            'email' => $prefixe.'-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
        $exercice = Exercise::factory()->create([
            'user_id' => $compte->id,
            'name' => 'Exercice '.$jeton,
            'type' => 'strength',
        ]);

        return [$compte, $exercice, $jeton];
    }

    /**
     * L'accueil chargé en ligne, le worker maître de la page, les copies de
     * l'accueil et de la bibliothèque dans IndexedDB : la séance démarrée sans
     * réseau y prend ses exercices, et la coquille aussi.
     */
    private function ouvrirLAccueil(Browser $browser, User $compte): ChromeDevToolsDriver
    {
        $browser->loginAs($compte)
            ->visit('/dashboard')
            ->waitFor('#main-content', 30);

        $this->exigerUnContexteSur($browser);
        $this->attendreLeWorker($browser);
        $browser->disableAnimations();
        $this->attendreLesCopies($browser, ['/dashboard', '/exercises']);
        $this->noterLesMessages($browser);

        return $this->outilsDuNavigateur($browser);
    }

    /**
     * Le bouton « Séance » de la barre du bas, sans réseau : la séance en
     * attente s'ouvre, et rien ne dit qu'il faut le réseau.
     *
     * @return string la clé que l'appareil a tirée
     */
    private function demarrerSansReseau(Browser $browser): string
    {
        $browser->waitFor('button.glass-nav-fab', 15)
            ->assertAttribute('button.glass-nav-fab', 'aria-label', 'Séance')
            ->script("document.querySelector('button.glass-nav-fab').click();");

        $browser->waitUntil(
            "location.pathname.startsWith('/workouts/en-attente/') && document.querySelector('[dusk=\"add-first-exercise\"]') !== null",
            15,
            'la séance démarrée sans réseau ne s’est pas ouverte : '.json_encode($this->messagesSansReseau($browser), JSON_UNESCAPED_UNICODE),
        );

        $this->assertSame([], $this->messagesSansReseau($browser), 'démarrer a dit quelque chose du réseau');

        $cle = $browser->script("return location.pathname.split('/').pop();")[0];
        $this->assertIsString($cle);

        $file = $this->laFile($browser);
        $this->assertCount(1, $file);
        $this->assertSame('/api/v1/workouts', $file[0]['chemin']);
        $this->assertSame($cle, $file[0]['cle']);

        return $cle;
    }

    /**
     * Un exercice ajouté, une série de 82,5 × 5 saisie et cochée : derrière la
     * séance dans la file, valeurs fondues dans l'entrée de la série.
     */
    private function ajouterLaSerie(Browser $browser, Exercise $exercice, string $jeton): void
    {
        $browser->clickWhenSettled('[dusk="add-first-exercise"]')
            ->waitFor('[dusk="select-exercise-'.$exercice->id.'"]', 15)
            ->clickWhenSettled('[dusk="select-exercise-'.$exercice->id.'"]')
            ->waitForText($jeton, 15, true)
            ->clickWhenSettled('[dusk="add-set-0"]')
            ->waitFor('[dusk="weight-input-0-0"]', 15)
            ->type('[dusk="weight-input-0-0"]', '82.5')
            ->type('[dusk="reps-input-0-0"]', '5')
            ->clickWhenSettled('[dusk="complete-set-0-0"]')
            ->waitUntil(self::LA_FILE_GARDE_LA_SEANCE, 15, 'la file ne garde pas la séance, sa ligne et sa série saisie et cochée');
    }
}
