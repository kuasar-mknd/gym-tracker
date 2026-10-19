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
 * Sans réseau, l'appareil ne montre rien du compte qui l'a quitté (#2048,
 * vie privée).
 *
 * Un appareil, deux comptes : A ouvre l'accueil et sa séance en cours, qui se
 * gardent chiffrées sous la clé de sa session, puis se déconnecte par le menu
 * « Plus » ; B se connecte sur le même navigateur et ouvre l'accueil. Le
 * réseau coupé, la séance de A, ouverte à son adresse, ne s'ouvre pas : la
 * coquille ne trouve pour B aucune copie de cette adresse, et monte la page
 * `HorsLigne`, qui ne liste que ce que B garde. Ni le nom de A, ni son
 * adresse, ni sa séance n'y paraissent. Dans IndexedDB, rien n'est rangé sous
 * le chemin de la séance de A, la clé de A (relevée dans son témoin avant la
 * déconnexion) n'ouvre plus rien, et ce que la clé de B ouvre ne porte rien
 * de A : le magasin ne garde que les copies d'un compte, sous la clé de sa
 * session.
 *
 * Le réseau de la page est coupé et public/build introuvable pendant la
 * coupure (`Tests\Support\ParcoursHorsLigne`). Les jetons cherchés sont tirés
 * au hasard, en ASCII. Le parcours exige un contexte sûr : la CI sert
 * 127.0.0.1 ; sous Sail, il se déclare sauté.
 */
class CopiesDUnAutreCompteTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    public function test_sans_reseau_rien_du_compte_parti_ne_s_ouvre_pour_le_suivant(): void
    {
        $jetonsDeA = [
            'nom' => Str::random(12),
            'adresse' => Str::lower(Str::random(12)),
            'seance' => Str::random(12),
            'exercice' => Str::random(12),
        ];
        $compteA = User::factory()->create([
            'name' => 'Alex '.$jetonsDeA['nom'],
            'email' => 'copies-a-'.$jetonsDeA['adresse'].'@example.org',
            'email_verified_at' => now(),
        ]);
        $compteB = User::factory()->create([
            'name' => 'Bea '.Str::random(12),
            'email' => 'copies-b-'.Str::lower(Str::random(12)).'@example.org',
            'email_verified_at' => now(),
        ]);
        $seanceDeA = Workout::factory()->for($compteA)->create([
            'name' => 'Seance '.$jetonsDeA['seance'],
            'started_at' => now()->subMinutes(20),
            'ended_at' => null,
        ]);
        $exercice = Exercise::factory()->create(['user_id' => $compteA->id, 'name' => 'Exercice '.$jetonsDeA['exercice'], 'type' => 'strength']);
        $ligne = WorkoutLine::factory()->create(['workout_id' => $seanceDeA->id, 'exercise_id' => $exercice->id, 'order' => 0]);
        Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 82.5, 'reps' => 7]);

        $cheminDeLaSeanceDeA = '/workouts/'.$seanceDeA->id;

        $this->browse(function (Browser $browser) use ($compteA, $compteB, $jetonsDeA, $cheminDeLaSeanceDeA): void {
            // A navigue : l'accueil, puis sa séance en cours.
            $browser->loginAs($compteA)
                ->visit('/dashboard')
                ->waitFor('#main-content', 30);

            $this->exigerUnContexteSur($browser);
            $this->attendreLeWorker($browser);

            $browser->disableAnimations()
                ->waitFor('[dusk="active-workout-banner"]', 30)
                ->clickWhenSettled('[dusk="active-workout-banner"]')
                ->waitForLocation($cheminDeLaSeanceDeA, 15)
                ->waitForText($jetonsDeA['exercice'], 15, true);

            $copiesDeA = $this->attendreLesCopies($browser, ['/dashboard', $cheminDeLaSeanceDeA]);
            $this->assertIsArray($copiesDeA['etat']);
            $this->assertSame((string) $compteA->id, $copiesDeA['etat']['compte']);

            $cleDeA = $this->temoinDesCopies($browser);
            $this->assertIsString($cleDeA, 'le témoin de A manque');

            // A se déconnecte par le menu « Plus ».
            $browser->clickWhenSettled('[dusk="nav-profile"]')
                ->waitForLocation('/profile', 15)
                ->clickWhenSettled('[data-testid="logout-button"]')
                ->waitForLocation('/login', 15)
                ->waitFor('input[type="email"]', 15);

            // B se connecte sur le même navigateur, et arrive sur l'accueil.
            $browser->type('input[type="email"]', $compteB->email)
                ->type('input[type="password"]', 'password')
                ->click('[data-testid="login-button"]')
                ->waitForLocation('/dashboard', 15);

            $copiesDeB = $this->attendreLesCopies($browser, ['/dashboard']);
            $this->assertIsArray($copiesDeB['etat']);
            $this->assertSame((string) $compteB->id, $copiesDeB['etat']['compte']);

            $cleDeB = $this->temoinDesCopies($browser);
            $this->assertIsString($cleDeB, 'le témoin de B manque');
            $this->assertNotSame($cleDeA, $cleDeB, 'la session de B garde la clé de A');

            $outils = $this->outilsDuNavigateur($browser);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser, $compteA, $compteB, $jetonsDeA, $cheminDeLaSeanceDeA, $cleDeA, $cleDeB): void {
                // La séance de A, à son adresse : la coquille n'a pour B que la page qui dit ce qui s'ouvre sans réseau.
                $browser->visit($cheminDeLaSeanceDeA)
                    ->waitUntil($this->laCoquilleServiePour('reseau'), 15, 'la coquille n’a pas été servie')
                    ->waitFor('[dusk="page-hors-ligne-sans-copie"]', 15)
                    ->assertPathIs($cheminDeLaSeanceDeA)
                    ->waitFor('[dusk="page-sans-reseau"]', 15);

                foreach ($jetonsDeA as $quoi => $jeton) {
                    $this->assertFalse(
                        $browser->script('return document.documentElement.outerHTML.includes('.json_encode($jeton, JSON_THROW_ON_ERROR).');')[0],
                        "la page montre à B ce qui vient de A : {$quoi}",
                    );
                }

                /** @var list<string> $liens */
                $liens = $browser->script("return [...document.querySelectorAll('[dusk=\"page-sans-reseau\"]')].map((lien) => new URL(lien.href).pathname);")[0];
                $this->assertNotContains($cheminDeLaSeanceDeA, $liens, 'la page propose à B la séance de A');

                // Le magasin : rien sous le chemin de A, rien que la clé de A ouvre, rien de A dans ce que la clé de B ouvre.
                $magasin = $this->lireLeMagasin($browser);
                $this->assertIsArray($magasin['etat']);
                $this->assertSame((string) $compteB->id, $magasin['etat']['compte']);
                $this->assertNotContains($cheminDeLaSeanceDeA, array_column($magasin['copies'], 'chemin'));

                foreach ([[$cleDeA, $compteA], [$cleDeA, $compteB], [$cleDeB, $compteA]] as [$cle, $compte]) {
                    $this->assertSame(
                        [],
                        array_values(array_filter($this->dechiffrerLeMagasin($browser, $cle, (string) $compte->id), static fn (array $copie): bool => $copie['texte'] !== null)),
                        'une copie s’ouvre sous une clé ou un compte qui ne sont pas les siens',
                    );
                }

                $deB = $this->dechiffrerLeMagasin($browser, $cleDeB, (string) $compteB->id);
                $this->assertNotSame([], $deB);

                foreach ($deB as $copie) {
                    $this->assertNotNull($copie['texte'], "la clé de B n’ouvre pas sa copie de {$copie['chemin']}");

                    foreach ($jetonsDeA as $quoi => $jeton) {
                        $this->assertStringNotContainsString($jeton, (string) $copie['texte'], "la copie de {$copie['chemin']} porte, pour B, ce qui vient de A : {$quoi}");
                    }
                }
            });
        });
    }
}
