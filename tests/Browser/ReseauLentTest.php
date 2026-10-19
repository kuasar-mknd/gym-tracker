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
use Tests\Support\ParcoursHorsLigne;

/**
 * Sur un réseau qui ne répond pas, une copie au bout de cinq secondes dans
 * l'application, la coquille au bout de huit à l'ouverture, et « Attendre la
 * réponse » mène à la page (#2048, décision 8 du propriétaire).
 *
 * Avant, rien n'avait de délai (constat f de l'issue) : un clic ne faisait
 * rien pendant trente secondes, et un nouvel onglet restait blanc soixante-
 * douze secondes. Le parcours rend le réseau lent par les outils de Chrome :
 * dix secondes de latence sur chaque requête de la page
 * (`Network.emulateNetworkConditions`). Cette latence touche aussi la requête
 * qu'une navigation précharge pour le worker : la coquille servie au délai le
 * prouve, puisque le serveur local répond sinon bien avant huit secondes. Le
 * worker, lui, sert ses caches sans latence (la coquille, le precache).
 *
 * Depuis la séance ouverte, Séances s'ouvre au bout de cinq secondes depuis
 * sa copie, sous « Le réseau ne répond pas » (`visitesHorsLigne.js`), sans le
 * toast de la lenteur. Une ouverture de l'accueil attend huit secondes la
 * réponse, puis le worker sert la coquille marquée `data-raison="delai"`,
 * qui démarre l'application sur la copie de l'accueil
 * (`navigationsHorsLigne.js`, `demarrageHorsLigne.js`) ; la réponse arrive
 * ensuite, le worker le dit à la page, et l'accueil du serveur remplace la
 * copie sans aucun geste (`messagesDuWorker.js`) : la sonde, qui abandonne à
 * cinq secondes, n'y suffisait pas sur ce réseau. Une séance close, que
 * le magasin n'a pas, donne au bout de huit secondes la page `HorsLigne` et
 * « Attendre la réponse » : le worker lève alors le délai de cette adresse, la
 * page se recharge, et la séance arrive du serveur, lentement.
 *
 * Le parcours exige un contexte sûr : la CI sert 127.0.0.1 ; sous Sail, il
 * se déclare sauté.
 */
class ReseauLentTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    /** La latence de chaque requête de la page : au-delà des cinq et des huit secondes. */
    private const int LATENCE_DU_RESEAU_LENT_MS = 10_000;

    /**
     * Ce qu'attend, au plus, l'accueil du serveur depuis l'ouverture : la
     * réponse tardive (une latence), puis le rechargement de la copie (une
     * autre), et de quoi absorber une machine chargée.
     */
    private const int SECONDES_JUSQU_A_L_ACCUEIL_DU_SERVEUR = 30;

    public function test_sur_un_reseau_lent_la_copie_arrive_a_cinq_secondes_la_coquille_a_huit_la_reponse_tardive_la_remplace_et_attendre_la_reponse_mene_a_la_page(): void
    {
        $jetons = ['seance' => Str::random(12), 'close' => Str::random(12), 'exercice' => Str::random(12)];
        $compte = User::factory()->create([
            'email' => 'reseau-lent-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);
        $seance = Workout::factory()->for($compte)->create([
            'name' => 'Seance '.$jetons['seance'],
            'started_at' => now()->subMinutes(20),
            'ended_at' => null,
        ]);
        $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Exercice '.$jetons['exercice'], 'type' => 'strength']);
        WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id, 'order' => 0]);
        $close = Workout::factory()->for($compte)->create([
            'name' => 'Close '.$jetons['close'],
            'started_at' => now()->subDays(2),
            'ended_at' => now()->subDays(2)->addHour(),
        ]);

        $cheminDeLaSeance = '/workouts/'.$seance->id;
        $cheminDeLaClose = '/workouts/'.$close->id;

        $this->browse(function (Browser $browser) use ($compte, $jetons, $cheminDeLaSeance, $cheminDeLaClose): void {
            $browser->loginAs($compte)
                ->visit('/dashboard')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);

            $browser->disableAnimations()
                ->waitFor('[dusk="active-workout-banner"]', 30)
                ->clickWhenSettled('[dusk="active-workout-banner"]')
                ->waitForLocation($cheminDeLaSeance, 15)
                ->waitForText($jetons['exercice'], 15, true);

            $magasin = $this->attendreLesCopies($browser, ['/dashboard', $cheminDeLaSeance, '/workouts']);
            $this->assertNotContains($cheminDeLaClose, array_column($magasin['copies'], 'chemin'), 'la séance close a déjà une copie');

            $this->noterLesMessages($browser);
            $outils = $this->outilsDuNavigateur($browser);

            try {
                $this->reglerLeReseau($outils, false, self::LATENCE_DU_RESEAU_LENT_MS);

                // Dans l'application : Séances ne répond pas, sa copie s'ouvre au bout de cinq secondes.
                $depart = microtime(true);
                $browser->clickWhenSettled('[dusk="nav-workouts"]')
                    ->waitForLocation('/workouts', 15)
                    ->waitFor('[dusk="bandeau-hors-ligne"]', 15);
                $duree = microtime(true) - $depart;

                $browser->assertScript($this->texteDe('[dusk="bandeau-hors-ligne-titre"]').".startsWith('Le réseau ne répond pas · copie du ')")
                    ->assertScript($this->texteDe('header h1'), 'Mes Séances');
                $this->assertGreaterThanOrEqual(5.0, $duree, 'la copie est arrivée avant les cinq secondes');
                // La copie a pris la place du toast de la lenteur, qui ne vient qu'au bout de huit secondes.
                $this->assertSame([], $this->messagesSansReseau($browser));

                // À l'ouverture : l'accueil attend huit secondes la requête préchargée, puis la coquille démarre sur sa copie.
                $depart = microtime(true);
                $browser->visit('/dashboard')
                    ->waitUntil($this->laCoquilleServiePour('delai'), 15, 'la coquille n’a pas été servie au délai : la latence ne touche pas la requête préchargée');
                $duree = microtime(true) - $depart;

                $browser->waitFor('[dusk="bandeau-hors-ligne"]', 15)
                    ->assertScript($this->texteDe('[dusk="bandeau-hors-ligne-titre"]').".startsWith('Le réseau ne répond pas · copie du ')")
                    ->assertScript($this->texteDe('header h1'), 'Accueil');
                $this->assertGreaterThanOrEqual(8.0, $duree, 'la coquille est arrivée avant les huit secondes');

                // La réponse arrive après le délai : sans aucun geste, l'accueil du serveur remplace la copie.
                $browser->waitUntilMissing('[dusk="bandeau-hors-ligne"]', self::SECONDES_JUSQU_A_L_ACCUEIL_DU_SERVEUR + 10)
                    ->assertPathIs('/dashboard')
                    ->assertScript($this->texteDe('header h1'), 'Accueil');
                $duree = microtime(true) - $depart;
                $this->assertLessThan(
                    (float) self::SECONDES_JUSQU_A_L_ACCUEIL_DU_SERVEUR,
                    $duree,
                    'l’accueil du serveur n’a pas remplacé la copie à temps',
                );

                // Une séance close sans copie : la page qui dit ce qui s'ouvre sans réseau, et « Attendre la réponse ».
                $browser->visit($cheminDeLaClose)
                    ->waitUntil($this->laCoquilleServiePour('delai'), 15, 'la coquille n’a pas été servie au délai')
                    ->waitFor('[dusk="page-hors-ligne-sans-copie"]', 15)
                    ->waitFor('[dusk="attendre-la-reponse"]', 15)
                    ->assertScript($this->texteDe('[dusk="attendre-la-reponse"]'), 'Attendre la réponse')
                    ->assertScript('!'.$this->laPageDit($jetons['close']));

                /*
                 * Le worker lève le délai de l'adresse, la page se recharge, et la séance arrive du serveur.
                 * Un clic par script : le rechargement part aussitôt, et le pilote attendrait la page
                 * suivante avant de rendre la main à `clickWhenSettled`, qui vérifie son clic dans l'ancienne.
                 */
                $browser->script("document.querySelector('[dusk=\"attendre-la-reponse\"]').click();");
                $browser->waitUntil("!document.documentElement.hasAttribute('data-coquille')", 40, 'la page n’est pas arrivée du serveur')
                    ->assertPathIs($cheminDeLaClose)
                    ->waitForText($jetons['close'], 15, true)
                    ->assertMissing('[dusk="bandeau-hors-ligne"]');
            } finally {
                $this->reglerLeReseau($outils, false);
            }
        });
    }
}
