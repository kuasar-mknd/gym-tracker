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
 *
 * Le parcours ne demande pas une base vide : la bibliothèque montre aussi le
 * catalogue commun (exercices sans compte, que la graine de la CI pose), et
 * l'ordre de passage des parcours change avec chaque pesée des éclats. Il
 * retrouve donc ses trois cartes parmi les autres, et compare l'écran entier
 * avant et après.
 *
 * Sous une souris, la carte ne montre ses boutons qu'au survol ou au focus
 * (`souris:opacity-0 souris:group-hover:opacity-100`, #2082). Chrome sans tête
 * n'a pas de pointeur (`pointer: none`) : la variante y est fausse, et le
 * bouton visible. Le parcours attend pourtant la carte, visible, puis clique
 * le bouton, comme `ExerciseListLiveUpdateTest` : il tient aussi sous un
 * Chrome à souris, où le bouton reste transparent jusqu'au survol et où
 * WebDriver le tiendrait pour caché (`BoutonsAuSurvolTest`).
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
        $premier = $exercice('Alpha');
        $milieu = $exercice('Bravo');
        $dernier = $exercice('Charlie');
        $carteDuMilieu = '[dusk="exercise-card-'.$milieu->id.'"]';
        $boutonDuMilieu = '[dusk="delete-exercise-btn-'.$milieu->id.'"]';
        $cartesDuCompte = array_map(
            static fn (Exercise $exerciceDuCompte): string => 'exercise-card-'.$exerciceDuCompte->id,
            [$premier, $milieu, $dernier],
        );

        $this->browse(function (Browser $browser) use ($compte, $carteDuMilieu, $boutonDuMilieu, $cartesDuCompte): void {
            // Un écran de bureau : au doigt, la carte cache sa suppression derrière un glissement.
            $browser->loginAs($compte)
                ->resize(1280, 900)
                ->visit('/exercises')
                ->disableAnimations()
                ->waitFor('#main-content', 30)
                ->waitFor($carteDuMilieu, 15);

            $ordreDesCartes = 'return [...document.querySelectorAll(\'[dusk^="exercise-card-"]\')].map((carte) => carte.getAttribute("dusk"));';
            $ordreAvant = $browser->script($ordreDesCartes)[0];

            $this->assertIsArray($ordreAvant);
            // Les trois cartes du compte, dans l'ordre des noms, parmi celles du catalogue commun.
            $this->assertSame($cartesDuCompte, array_values(array_filter(
                $ordreAvant,
                static fn (mixed $carte): bool => in_array($carte, $cartesDuCompte, true),
            )));

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
                    ->waitFor($carteDuMilieu, 15);

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
