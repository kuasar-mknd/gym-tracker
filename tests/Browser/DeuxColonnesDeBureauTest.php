<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Enums\PersonalRecordType;
use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Corps, Records, Progrès (les deux cartes de la période) et Profil passent sur deux colonnes à partir
 * de `bureau:` (lot 8, #2434), et seulement là : sous le breakpoint, la mise en
 * page reste celle du téléphone (pas de grille, donc pas de carte étirée). Un
 * état vide ne sort pas de sa carte : sous une grille, son `h-full` prenait la
 * hauteur de la rangée et le lien « Ajouter une mensuration » tombait dehors.
 */
class DeuxColonnesDeBureauTest extends DuskTestCase
{
    use DatabaseTruncation;

    /** La mise en page de la première boîte qui s'ouvre sur deux colonnes à `bureau:`. */
    private const string LA_MISE_EN_PAGE = <<<'JS'
        const boite = [...document.querySelectorAll('#main-content [class~="bureau:grid-cols-2"]')].find((element) => element.getClientRects().length > 0);

        if (boite === undefined) {
            return null;
        }

        const style = getComputedStyle(boite);

        return { affichage: style.display, colonnes: style.gridTemplateColumns.split(' ').length };
        JS;

    /** Le lien de la carte des mensurations est-il dans sa carte ? Écarts en pixels, négatifs s'il dépasse. */
    private const string LE_LIEN_EST_DANS_SA_CARTE = <<<'JS'
        const lien = document.querySelector('#main-content a[href$="/body-metrics"]');
        const carte = lien?.closest('.rounded-carte');

        if (!lien || !carte) {
            return null;
        }

        const l = lien.getBoundingClientRect();
        const c = carte.getBoundingClientRect();

        return { haut: Math.round(l.top - c.top), bas: Math.round(c.bottom - l.bottom), gauche: Math.round(l.left - c.left), droite: Math.round(c.right - l.right) };
        JS;

    private function unCompte(string $nom): User
    {
        return User::factory()->create(['email' => $nom.'-'.time().random_int(0, 999).'@example.com', 'email_verified_at' => now()]);
    }

    private function avecDesDonnees(User $compte): void
    {
        // Une séance faite : sans elle, Progrès est un compte neuf et n'a que son état vide, sans les deux cartes de la période.
        Workout::factory()->faite()->create(['user_id' => $compte->id, 'started_at' => now()->subDays(3), 'ended_at' => now()->subDays(3)->addHour()]);
        BodyMeasurement::factory()->create(['user_id' => $compte->id, 'weight' => '83.70', 'body_fat' => '19.30', 'measured_at' => now()->subDay()->toDateString(), 'notes' => null]);
        BodyPartMeasurement::factory()->create(['user_id' => $compte->id, 'part' => 'Waist', 'value' => 81, 'unit' => 'cm', 'measured_at' => now()->subDay()->toDateString(), 'notes' => null]);

        foreach (['Squat', 'Tractions', 'Soulevé de terre'] as $nom) {
            $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'name' => $nom, 'type' => 'strength']);

            PersonalRecord::factory()->createQuietly([
                'user_id' => $compte->id,
                'exercise_id' => $exercice->id,
                'workout_id' => null,
                'type' => PersonalRecordType::MaxWeight,
                'value' => 100,
                'secondary_value' => 5,
                'achieved_at' => now()->subDays(2),
            ]);
        }
    }

    public function test_les_pages_s_ouvrent_sur_deux_colonnes_a_partir_de_bureau_et_pas_avant(): void
    {
        $compte = $this->unCompte('colonnes');
        $this->avecDesDonnees($compte);

        $this->browse(function (Browser $browser) use ($compte): void {
            $browser->loginAs(User::findOrFail($compte->id));

            // Adresse => colonnes sous le breakpoint (null : la boîte n'est pas une grille).
            foreach (['/corps' => null, '/records' => null, '/stats' => null, '/profile' => 1] as $adresse => $etroit) {
                foreach ([375 => $etroit, 1280 => 2] as $largeur => $colonnes) {
                    $browser->resize($largeur, 900)->visit($adresse)->disableAnimations()->waitFor('#main-content', 30)
                        ->waitUntil("document.querySelectorAll('#main-content .glass-skeleton').length === 0", 30, "$adresse attend encore ses données")
                        ->waitForStableLayout();

                    /** @var array{affichage: string, colonnes: int}|null $mise */
                    $mise = $browser->script(self::LA_MISE_EN_PAGE)[0];

                    $this->assertNotNull($mise, "$adresse à $largeur px n'a pas sa boîte à deux colonnes.");

                    if ($colonnes === null) {
                        $this->assertNotSame('grid', $mise['affichage'], "$adresse à $largeur px est en grille sous le breakpoint de bureau.");
                    } else {
                        $this->assertSame('grid', $mise['affichage'], "$adresse à $largeur px n'est pas en grille.");
                        $this->assertSame($colonnes, $mise['colonnes'], "$adresse à $largeur px n'a pas $colonnes colonne(s).");
                    }
                }
            }
        });
    }

    public function test_le_lien_de_la_carte_des_mensurations_reste_dans_sa_carte(): void
    {
        $vide = $this->unCompte('mensurations-vides');
        $plein = $this->unCompte('mensurations-pleines');
        $this->avecDesDonnees($plein);

        $this->browse(function (Browser $browser) use ($vide, $plein): void {
            foreach ([$vide, $plein] as $compte) {
                $browser->loginAs(User::findOrFail($compte->id));

                foreach ([375, 320, 1280] as $largeur) {
                    $browser->resize($largeur, 900)->visit('/corps')->disableAnimations()->waitFor('#main-content', 30)
                        ->waitUntil("document.querySelectorAll('#main-content .glass-skeleton').length === 0", 30, 'Corps attend encore ses données')
                        ->waitForStableLayout();

                    /** @var array{haut: int, bas: int, gauche: int, droite: int}|null $ecarts */
                    $ecarts = $browser->script(self::LE_LIEN_EST_DANS_SA_CARTE)[0];
                    $ou = "$compte->email à $largeur px";

                    $this->assertNotNull($ecarts, "$ou : le lien des mensurations est introuvable.");
                    $this->assertGreaterThanOrEqual(0, min($ecarts), "$ou : le lien sort de sa carte (".json_encode($ecarts).').');
                }
            }
        });
    }
}
