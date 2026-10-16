<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * La déconnexion prévient, puis efface ce que la file hors ligne garde pour le
 * compte (#2050).
 *
 * La file garde en clair, dans le stockage du navigateur, la charge de chaque
 * écriture faite sans réseau. La déconnexion la laissait pour le retour du
 * compte (#1964), lisible par la personne suivante. File non vide, elle ne part
 * plus sans demander : « Rester connecté » garde tout ; « Se déconnecter quand
 * même » déconnecte, et la file du compte, ses refus et la dernière recherche
 * d'exercice quittent l'appareil, ce que l'écran de connexion dit.
 *
 * L'écriture en attente est posée dans le stockage avant un chargement de la
 * page, comme une série saisie sans réseau, avec un prochain essai dans une
 * heure (`prochainEssai`, la file l'ayant vue échouer sur une erreur
 * passagère) : le vidage du chargement ne l'envoie pas, et le parcours ne
 * dépend pas du réseau.
 */
class DeconnexionAvecDesEcrituresEnAttenteTest extends DuskTestCase
{
    public function test_la_deconnexion_previent_puis_efface_les_ecritures_en_attente(): void
    {
        $compte = User::factory()->create([
            'email' => 'file-privee-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs($compte)
                ->visit('/profile')
                ->disableAnimations()
                ->waitFor('[data-testid="logout-button"]', 30);

            $entree = json_encode([[
                'method' => 'patch',
                'url' => '/api/v1/sets/1',
                'data' => ['weight' => 80, 'notes' => 'Note privée'],
                'id' => 'parcours-2050',
                'compte' => (string) $compte->id,
                'transientAttempts' => 1,
            ]], JSON_THROW_ON_ERROR);
            // La recherche d'exercice est rangée avec son compte (#2050).
            $recherche = json_encode(['compte' => (string) $compte->id, 'texte' => 'Squat'], JSON_THROW_ON_ERROR);

            $browser->script(<<<JS
                const entrees = {$entree}.map((entree) => ({
                    ...entree,
                    timestamp: new Date().toISOString(),
                    prochainEssai: Date.now() + 60 * 60 * 1000,
                }));
                localStorage.setItem('offline_sync_queue', JSON.stringify(entrees));
                localStorage.setItem('gymtracker_add_exercise_search', JSON.stringify({$recherche}));
            JS);

            // Un nouveau chargement : la file lit l'écriture en attente.
            $browser->visit('/profile')
                ->waitFor('[data-testid="logout-button"]', 30)
                ->clickWhenSettled('[data-testid="logout-button"]')
                ->waitFor('[dusk="confirm-dialog-cancel"]', 15)
                ->assertSee("Une modification faite hors ligne n'a pas encore été envoyée.")
                ->assertSeeIn('[dusk="confirm-dialog-cancel"]', 'Rester connecté')
                ->assertSeeIn('[dusk="confirm-dialog-confirm"]', 'Se déconnecter quand même')
                ->clickWhenSettled('[dusk="confirm-dialog-cancel"]')
                ->waitUntilMissing('[dusk="confirm-dialog-cancel"]', 15)
                ->assertPathIs('/profile');

            $this->assertSame(1, $this->entreesEnFile($browser), 'rester connecté garde l’écriture');

            $browser->clickWhenSettled('[data-testid="logout-button"]')
                ->waitFor('[dusk="confirm-dialog-confirm"]', 15)
                ->clickWhenSettled('[dusk="confirm-dialog-confirm"]')
                ->waitForLocation('/login', 15)
                ->waitFor('[dusk="ecritures-effacees-a-la-deconnexion"]', 15)
                ->assertSeeIn(
                    '[dusk="ecritures-effacees-a-la-deconnexion"]',
                    "Une modification faite hors ligne n'avait pas encore été envoyée : elle a été effacée de cet appareil avec la déconnexion.",
                );

            $this->assertSame(0, $this->entreesEnFile($browser), 'la déconnexion acceptée efface la file du compte');
            $this->assertNull(
                $browser->script("return localStorage.getItem('gymtracker_add_exercise_search');")[0],
                'la recherche d’exercice part avec la déconnexion',
            );
            $this->assertFalse(
                $browser->script("return Object.values({ ...localStorage }).some((valeur) => valeur.includes('Note privée'));")[0],
                'rien de la charge ne reste sur l’appareil',
            );
        });
    }

    private function entreesEnFile(Browser $browser): int
    {
        /** @var int $nombre */
        $nombre = $browser->script("return JSON.parse(localStorage.getItem('offline_sync_queue') || '[]').length;")[0];

        return $nombre;
    }
}
