<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ParcoursHorsLigne;

/**
 * Une session close côté serveur rend les copies illisibles, sans qu'aucune
 * page n'ait à le savoir (#2048, vie privée).
 *
 * La clé des copies ne vit que dans le témoin, que le serveur pose avec la
 * session et efface sur toute réponse qui en dit la fin
 * (`CleDesCopiesHorsLigne`, `.ai/rules/middleware.md`). Ici, le mot de passe
 * du compte change ailleurs (`forceFill`, comme un changement depuis un autre
 * appareil ou le panneau) pendant que l'appareil garde ses copies. La requête
 * suivante du navigateur reçoit la redirection vers la connexion
 * (`AuthentifieLaSessionDuCompte`), lue sans la suivre (`fetch` en
 * `redirect: 'manual'`), et le témoin part avec elle. Le réseau coupé
 * ensuite, la coquille ne trouve plus de clé : elle garde « Pas de réseau »,
 * ne montre rien du compte, et vide le magasin, où rien n'était plus lisible.
 *
 * Le réseau de la page est coupé et public/build introuvable pendant la
 * coupure (`Tests\Support\ParcoursHorsLigne`). Le parcours exige un contexte
 * sûr : la CI sert 127.0.0.1 ; sous Sail, il se déclare sauté.
 *
 * Chaque visite de la coupure part de la page blanche d'un onglet neuf,
 * l'onglet quitté fermé, et la coquille attendue est celle de l'adresse
 * visitée (#2091) : la coquille de l'accueil, laissée telle quelle faute de
 * clé, se recharge au premier `online`, que Chrome émet dans le document
 * quitté quand le pilote navigue. Ce rechargement prenait la place de la
 * visite de la séance, que le parcours croyait vérifier, puis, sous Chrome
 * 155, celle de la page blanche demandée dans le même onglet.
 */
class FinDeSessionCoteServeurTest extends DuskTestCase
{
    use ParcoursHorsLigne;

    /**
     * Une requête du navigateur, comme une visite d'Inertia, dont la réponse
     * se lit sans suivre sa redirection : son type et son statut.
     */
    private const string DEMANDER_SANS_SUIVRE = <<<'SCRIPT'
        const [adresse, fini] = arguments;
        fetch(adresse, {
            redirect: 'manual',
            cache: 'no-store',
            headers: { Accept: 'text/html, application/xhtml+xml', 'X-Inertia': 'true', 'X-Requested-With': 'XMLHttpRequest' },
        }).then(
            (reponse) => fini({ type: reponse.type, statut: reponse.status }),
            (erreur) => fini({ type: 'erreur', statut: String(erreur) }),
        );
    SCRIPT;

    public function test_un_mot_de_passe_change_ailleurs_rend_les_copies_illisibles_sans_reseau(): void
    {
        $jetons = [
            'nom' => Str::random(12),
            'adresse' => Str::lower(Str::random(12)),
            'seance' => Str::random(12),
            'exercice' => Str::random(12),
        ];
        $compte = User::factory()->create([
            'name' => 'Camille '.$jetons['nom'],
            'email' => 'fin-de-session-'.$jetons['adresse'].'@example.org',
            'email_verified_at' => now(),
        ]);
        $seance = Workout::factory()->for($compte)->create([
            'name' => 'Seance '.$jetons['seance'],
            'started_at' => now()->subMinutes(20),
            'ended_at' => null,
        ]);
        $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Exercice '.$jetons['exercice'], 'type' => 'strength']);
        WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id, 'order' => 0]);

        $cheminDeLaSeance = '/workouts/'.$seance->id;

        $this->browse(function (Browser $browser) use ($compte, $jetons, $cheminDeLaSeance): void {
            $browser->visit('/login')
                ->waitFor('input[type="email"]', 30)
                ->type('input[type="email"]', $compte->email)
                ->type('input[type="password"]', 'password')
                ->click('[data-testid="login-button"]')
                ->waitForLocation('/dashboard', 15);

            $this->exigerUnContexteSur($browser);

            $browser->visit('/dashboard')
                ->waitFor('#main-content', 30);
            $this->attendreLeWorker($browser);

            $browser->disableAnimations()
                ->waitFor('[dusk="active-workout-banner"]', 30)
                ->clickWhenSettled('[dusk="active-workout-banner"]')
                ->waitForLocation($cheminDeLaSeance, 15)
                ->waitForText($jetons['exercice'], 15, true);

            $this->attendreLesCopies($browser, ['/dashboard', $cheminDeLaSeance]);
            $this->assertIsString($this->temoinDesCopies($browser), 'le témoin manque avant la fin de la session');

            // Le mot de passe change ailleurs : la session de l'appareil est close côté serveur.
            User::query()->findOrFail($compte->id)->forceFill(['password' => Hash::make('un-autre-mot-de-passe-'.Str::random(8))])->save();

            // La requête suivante du navigateur reçoit la redirection vers la connexion, et le témoin part avec elle.
            // Le pilote rend les clés d'un objet dans son ordre à lui : seules les paires comptent.
            $this->assertEquals(
                ['type' => 'opaqueredirect', 'statut' => 0],
                $browser->driver->executeAsyncScript(self::DEMANDER_SANS_SUIVRE, ['/dashboard']),
                'la session close n’a pas répondu par une redirection',
            );
            $this->assertNull($this->temoinDesCopies($browser), 'le témoin reste après la fin de la session');

            $outils = $this->outilsDuNavigateur($browser);

            $this->sansReseauNiConstruction($browser, $outils, function () use ($browser, $compte, $jetons, $cheminDeLaSeance): void {
                foreach (['/dashboard', $cheminDeLaSeance] as $chemin) {
                    $this->attendreLaCoquilleServieA($this->partirDUnePageBlanche($browser)->visit($chemin), $chemin)
                        ->waitFor('[dusk="page-hors-ligne"]', 15)
                        ->assertScript($this->texteDe('[dusk="page-hors-ligne"] h1'), 'Pas de réseau')
                        ->assertMissing('#app')
                        ->assertMissing('[dusk="bandeau-hors-ligne"]');

                    foreach ([...array_values($jetons), $compte->email] as $jeton) {
                        $this->assertFalse(
                            $browser->script('return document.documentElement.outerHTML.includes('.json_encode($jeton, JSON_THROW_ON_ERROR).');')[0],
                            "la coquille montre ce qui vient du compte à {$chemin}",
                        );
                    }
                }

                // Sans clé, aucune copie n'était lisible : la coquille a vidé le magasin.
                $magasin = $this->attendreUnMagasinVide($browser, 'des copies restent après la fin de la session');
                $this->assertTrue($magasin['etat'] === null || $magasin['etat']['ouvert'] === false, 'le magasin reste ouvert sans clé');
            });

            // Le réseau rendu, le serveur renvoie vers la connexion.
            $browser->visit('/dashboard')
                ->waitForLocation('/login', 15)
                ->assertMissing('[dusk="page-hors-ligne"]');
        });
    }
}
