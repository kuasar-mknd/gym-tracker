<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Http\Controllers\GoalController;
use App\Models\BodyPartMeasurement;
use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

/*
 * Les treize parties proposées sur Mensurations s'affichaient sous leur clef
 * anglaise : « Waist », « Thigh L », « Calf R », en pastille, en titre de carte
 * et de page, et dans les options d'un objectif, à côté de « Poids de corps »
 * (#1974). La clef reste en base — les mesures et les objectifs s'y
 * rapportent — et l'écran reçoit son nom français.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 10:00:00');
});

it('nomme chaque partie proposée en français, et elles seules', function (): void {
    expect(array_keys(BodyPartMeasurement::LIBELLES))->toBe(BodyPartMeasurement::COMMON_PARTS);

    foreach (BodyPartMeasurement::LIBELLES as $clef => $libelle) {
        expect($libelle)->not->toBe($clef)
            ->and(BodyPartMeasurement::libelle($clef))->toBe($libelle)
            ->and(BodyPartMeasurement::clefDePartie($libelle))->toBe($clef);
    }

    expect(BodyPartMeasurement::libelle('waist'))->toBe('Taille')
        ->and(BodyPartMeasurement::libelle('Tour de cou'))->toBe('Tour de cou')
        ->and(BodyPartMeasurement::clefDePartie('  mollet GAUCHE '))->toBe('Calf L')
        ->and(BodyPartMeasurement::clefDePartie('Tour de cou'))->toBe('Tour de cou');
});

it('propose les mensurations d’un objectif sous leur nom français', function (): void {
    $libelles = array_column(GoalController::measurementTypes(), 'label');

    expect($libelles)->toBe([
        'Poids de corps',
        'Masse grasse (%)',
        'Cou',
        'Épaules',
        'Poitrine',
        'Biceps gauche',
        'Biceps droit',
        'Avant-bras gauche',
        'Avant-bras droit',
        'Taille',
        'Hanches',
        'Cuisse gauche',
        'Cuisse droite',
        'Mollet gauche',
        'Mollet droit',
    ])
        ->and(array_intersect($libelles, BodyPartMeasurement::COMMON_PARTS))->toBe([])
        ->and(array_column(GoalController::measurementTypes(), 'value'))
        ->toBe(['weight', 'body_fat', ...BodyPartMeasurement::COMMON_PARTS]);
});

it('sert à la page les noms français des pastilles, des cartes et du détail', function (): void {
    $utilisateur = User::factory()->create();
    BodyPartMeasurement::factory()->create(['user_id' => $utilisateur->id, 'part' => 'Thigh L', 'value' => 58, 'measured_at' => '2026-10-01']);
    BodyPartMeasurement::factory()->create(['user_id' => $utilisateur->id, 'part' => 'Tour de cou', 'value' => 38, 'measured_at' => '2026-10-01']);

    $this->actingAs($utilisateur)
        ->get(route('body-parts.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('commonParts.7', ['value' => 'Waist', 'label' => 'Taille'])
            ->where('latestMeasurements.0.part', 'Thigh L')
            ->where('latestMeasurements.0.label', 'Cuisse gauche')
            ->where('latestMeasurements.1.part', 'Tour de cou')
            ->where('latestMeasurements.1.label', 'Tour de cou'));

    $this->actingAs($utilisateur)
        ->get(route('body-parts.show', ['part' => 'Thigh L']))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('part', 'Thigh L')
            ->where('label', 'Cuisse gauche'));
});

it('range sous sa clef une partie saisie sous son nom français, et laisse une partie libre telle quelle', function (string $saisie, string $rangee): void {
    $utilisateur = User::factory()->create();

    $this->actingAs($utilisateur)
        ->post(route('body-parts.store'), [
            'part' => $saisie,
            'value' => 80,
            'unit' => 'cm',
            'measured_at' => '2026-10-05',
        ])
        ->assertSessionHasNoErrors();

    expect(BodyPartMeasurement::query()->where('user_id', $utilisateur->id)->value('part'))->toBe($rangee);
})->with([
    'pastille' => ['Taille', 'Waist'],
    'saisie à la main' => ['  mollet gauche', 'Calf L'],
    'clef' => ['Waist', 'Waist'],
    'partie libre' => ['Tour de cou', 'Tour de cou'],
]);

it('ajoute une mesure à une partie déjà saisie sous un nom français, sans couper son historique', function (string $partieSaisieAvant, string $page): void {
    $utilisateur = User::factory()->create();
    BodyPartMeasurement::factory()->create(['user_id' => $utilisateur->id, 'part' => $partieSaisieAvant, 'value' => 90, 'unit' => 'cm', 'measured_at' => '2026-09-01']);

    $this->actingAs($utilisateur)
        ->post(route('body-parts.store'), ['part' => 'Taille', 'value' => 88, 'unit' => 'cm', 'measured_at' => '2026-10-05'])
        ->assertSessionHasNoErrors();

    expect(BodyPartMeasurement::query()->where('user_id', $utilisateur->id)->where('part', 'Waist')->exists())->toBeFalse();

    $this->actingAs($utilisateur)
        ->get(route('body-parts.show', ['part' => $page]))
        ->assertInertia(fn (AssertableInertia $detail): AssertableInertia => $detail
            ->where('label', $page)
            ->has('history', 2)
            ->where('history.1.value', '88.00'));

    $this->actingAs($utilisateur)
        ->get(route('body-parts.index'))
        ->assertInertia(fn (AssertableInertia $liste): AssertableInertia => $liste
            ->has('latestMeasurements', 1)
            ->where('latestMeasurements.0.label', 'Taille')
            ->where('latestMeasurements.0.current', 88)
            ->where('latestMeasurements.0.diff', -2));
})->with([
    'même casse' => ['Taille', 'Taille'],
    'autre casse' => ['taille', 'Taille'],
]);

it('range sous sa clef le nom français d’un compte qui ne l’a jamais saisi, même quand un autre compte l’a fait', function (): void {
    $autre = User::factory()->create();
    BodyPartMeasurement::factory()->create(['user_id' => $autre->id, 'part' => 'Taille', 'measured_at' => '2026-09-01']);
    $utilisateur = User::factory()->create();

    $this->actingAs($utilisateur)
        ->post(route('body-parts.store'), ['part' => 'Taille', 'value' => 88, 'unit' => 'cm', 'measured_at' => '2026-10-05'])
        ->assertSessionHasNoErrors();

    expect(BodyPartMeasurement::query()->where('user_id', $utilisateur->id)->pluck('part')->all())->toBe(['Waist']);
});

it('laisse un objectif existant suivre une mesure saisie sous le nom français, sans migration', function (): void {
    $utilisateur = User::factory()->create();
    $objectif = Goal::factory()->create([
        'user_id' => $utilisateur->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'Waist',
        'start_value' => 90,
        'current_value' => 90,
        'target_value' => 80,
        'completed_at' => null,
    ]);

    $this->actingAs($utilisateur)
        ->post(route('body-parts.store'), ['part' => 'Taille', 'value' => 84.5, 'unit' => 'cm', 'measured_at' => '2026-10-05'])
        ->assertSessionHasNoErrors();

    app(GoalService::class)->updateGoalProgress($objectif);

    expect((float) $objectif->current_value)->toBe(84.5);
});
