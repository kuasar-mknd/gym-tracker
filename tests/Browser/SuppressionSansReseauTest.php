<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Une suppression partie sans réseau remet la ligne à sa place, et le dit (#2077).
 *
 * La bibliothèque retire l'exercice de l'écran avant la réponse du serveur. Elle
 * ne le remettait que sur un refus de validation (`onError`) : sans réseau,
 * l'exercice restait parti de l'écran alors que rien n'était supprimé, jusqu'au
 * prochain chargement. Il revient désormais à sa place exacte sur tout échec,
 * et le filet des visites dit que la suppression n'est pas confirmée (#2069).
 *
 * Le réseau est coupé par les outils de développement de Chrome, sur la page :
 * la suppression part en XHR, que le service worker laisse au réseau (il ne
 * prend que les GET), et elle échoue comme sur un téléphone sans réseau.
 * Les cas des réponses en erreur et des visites annulées se jouent en Vitest,
 * avec le vrai client d'Inertia (`tests/js/pages/misesAJourOptimistesAvecInertia.test.js`).
 */
class SuppressionSansReseauTest extends DuskTestCase
{
    private const string SUPPRESSION_NON_CONFIRMEE = "Pas de réponse du serveur : la suppression n'est pas confirmée.";

    public function test_un_exercice_supprime_sans_reseau_revient_a_sa_place_et_le_dit(): void
    {
        $compte = User::factory()->create([
            'email' => 'suppression-sans-reseau-'.Str::lower(Str::random(10)).'@example.org',
            'email_verified_at' => now(),
        ]);

        // Une seule catégorie : la bibliothèque groupe par catégorie, et l'ordre se lit dans un groupe.
        $exercice = static fn (string $nom): Exercise => Exercise::factory()->for($compte)->create([
            'name' => $nom.' '.Str::random(6),
            'category' => 'Pectoraux',
        ]);
        $exercice('Alpha');
        $milieu = $exercice('Bravo');
        $exercice('Charlie');
        $boutonDuMilieu = '[dusk="delete-exercise-btn-'.$milieu->id.'"]';

        $this->browse(function (Browser $browser) use ($compte, $boutonDuMilieu): void {
            // Un écran de bureau : au doigt, la carte cache sa suppression derrière un glissement.
            $browser->loginAs($compte)
                ->resize(1280, 900)
                ->visit('/exercises')
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor($boutonDuMilieu, 15);

            $ordreDesCartes = 'return [...document.querySelectorAll(\'[dusk^="exercise-card-"]\')].map((carte) => carte.getAttribute("dusk"));';
            $ordreAvant = $browser->script($ordreDesCartes)[0];

            $this->assertIsArray($ordreAvant);
            $this->assertCount(3, $ordreAvant);

            $outils = new ChromeDevToolsDriver($browser->driver);
            $outils->execute('Network.enable');
            $outils->execute('Network.emulateNetworkConditions', [
                'offline' => true,
                'latency' => 0,
                'downloadThroughput' => -1,
                'uploadThroughput' => -1,
            ]);

            try {
                $browser->click($boutonDuMilieu)
                    ->waitFor('@confirm-dialog-confirm', 10)
                    ->click('@confirm-dialog-confirm')
                    // Le texte se lit dans le DOM : WebDriver rendrait le texte que la feuille transforme.
                    ->waitUntil('document.body.textContent.includes('.json_encode(self::SUPPRESSION_NON_CONFIRMEE, JSON_THROW_ON_ERROR).')', 15)
                    ->waitFor($boutonDuMilieu, 15);

                $this->assertSame($ordreAvant, $browser->script($ordreDesCartes)[0]);
            } finally {
                $outils->execute('Network.emulateNetworkConditions', [
                    'offline' => false,
                    'latency' => 0,
                    'downloadThroughput' => -1,
                    'uploadThroughput' => -1,
                ]);
            }
        });

        $this->assertDatabaseHas('exercises', ['id' => $milieu->id, 'user_id' => $compte->id]);
    }
}
