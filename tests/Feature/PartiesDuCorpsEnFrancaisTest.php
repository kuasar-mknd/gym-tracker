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

/*
 * Le compte qui a les deux historiques : « Waist », suivi par un objectif, et
 * « Taille », saisi à la main quand les pastilles étaient anglaises. La
 * pastille « Taille » nourrit l'objectif ; la page de la partie saisie à la
 * main garde ses mesures ; et les deux cartes ne s'intitulent pas pareil.
 */

/**
 * Un compte qui mesure son tour de taille sous la clef, suivie par un
 * objectif, et sous « Taille », saisi à la main.
 *
 * @return array{0: User, 1: Goal}
 */
function compteAuxDeuxHistoriquesDeTaille(): array
{
    $utilisateur = User::factory()->create();
    BodyPartMeasurement::factory()->create(['user_id' => $utilisateur->id, 'part' => 'Waist', 'value' => 90, 'unit' => 'cm', 'measured_at' => '2026-09-01']);
    BodyPartMeasurement::factory()->create(['user_id' => $utilisateur->id, 'part' => 'Taille', 'value' => 91, 'unit' => 'cm', 'measured_at' => '2026-09-02']);
    $objectif = Goal::factory()->create([
        'user_id' => $utilisateur->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'Waist',
        'start_value' => 90,
        'current_value' => 90,
        'target_value' => 80,
        'completed_at' => null,
    ]);

    return [$utilisateur, $objectif];
}

/**
 * Le nombre de mesures du compte rangées sous chaque nom, au caractère près.
 *
 * @return array<string, int>
 */
function mesuresDuCompteParPartie(User $utilisateur): array
{
    /** @var list<string> $parties */
    $parties = BodyPartMeasurement::query()->where('user_id', $utilisateur->id)->pluck('part')->all();
    $compte = array_count_values($parties);
    ksort($compte);

    return $compte;
}

it('range sous la clef la mesure de la pastille quand le compte mesure aussi la partie à la main, et son objectif avance', function (): void {
    [$utilisateur, $objectif] = compteAuxDeuxHistoriquesDeTaille();

    $this->actingAs($utilisateur)
        ->post(route('body-parts.store'), ['part' => 'Taille', 'value' => 84, 'unit' => 'cm', 'measured_at' => '2026-10-05'])
        ->assertSessionHasNoErrors();

    expect(mesuresDuCompteParPartie($utilisateur))->toBe(['Taille' => 1, 'Waist' => 2])
        ->and((float) $objectif->fresh()?->current_value)->toBe(84.0);
});

it('garde la mesure ajoutée depuis la page de la partie saisie à la main dans cet historique, même quand le compte mesure aussi la clef', function (): void {
    [$utilisateur, $objectif] = compteAuxDeuxHistoriquesDeTaille();

    $this->actingAs($utilisateur)
        ->post(route('body-parts.store'), ['part' => 'Taille', 'keep_part_name' => true, 'value' => 89, 'unit' => 'cm', 'measured_at' => '2026-10-05'])
        ->assertSessionHasNoErrors();

    expect(mesuresDuCompteParPartie($utilisateur))->toBe(['Taille' => 2, 'Waist' => 1])
        ->and((float) $objectif->fresh()?->current_value)->toBe(90.0);

    $this->actingAs($utilisateur)
        ->get(route('body-parts.show', ['part' => 'Taille']))
        ->assertInertia(fn (AssertableInertia $detail): AssertableInertia => $detail
            ->has('history', 2)
            ->where('history.1.value', '89.00'));
});

it('ne garde le nom demandé tel quel que pour une partie que le compte mesure déjà sous ce nom', function (): void {
    $utilisateur = User::factory()->create();

    $this->actingAs($utilisateur)
        ->post(route('body-parts.store'), ['part' => 'Taille', 'keep_part_name' => true, 'value' => 88, 'unit' => 'cm', 'measured_at' => '2026-10-05'])
        ->assertSessionHasNoErrors();

    expect(mesuresDuCompteParPartie($utilisateur))->toBe(['Waist' => 1]);
});

it('distingue, sur la carte et sur la page, la partie saisie à la main de la partie proposée du même nom', function (): void {
    [$utilisateur] = compteAuxDeuxHistoriquesDeTaille();

    $this->actingAs($utilisateur)
        ->get(route('body-parts.index'))
        ->assertInertia(fn (AssertableInertia $liste): AssertableInertia => $liste
            ->has('latestMeasurements', 2)
            ->where('latestMeasurements.0.part', 'Taille')
            ->where('latestMeasurements.0.label', 'Taille (saisie libre)')
            ->where('latestMeasurements.1.part', 'Waist')
            ->where('latestMeasurements.1.label', 'Taille'));

    $this->actingAs($utilisateur)
        ->get(route('body-parts.show', ['part' => 'Taille']))
        ->assertInertia(fn (AssertableInertia $detail): AssertableInertia => $detail
            ->where('part', 'Taille')
            ->where('label', 'Taille (saisie libre)'));

    $this->actingAs($utilisateur)
        ->get(route('body-parts.show', ['part' => 'Waist']))
        ->assertInertia(fn (AssertableInertia $detail): AssertableInertia => $detail
            ->where('part', 'Waist')
            ->where('label', 'Taille'));
});

/*
 * Le compte qui n'a encore rien rangé sous la clef, mais qu'un objectif suit :
 * « Taille » saisi à la main, et un objectif sur `Waist`, qui s'affiche lui
 * aussi « Taille ». La pastille « Taille » doit nourrir l'objectif ; la page
 * de la partie saisie à la main garde ses mesures.
 */

/**
 * Un compte qui mesure son tour de taille sous « Taille », saisi à la main,
 * et dont un objectif suit la clef, sans aucune mesure rangée sous elle.
 *
 * @return array{0: User, 1: Goal}
 */
function compteDontLObjectifSuitLaClefSansMesure(): array
{
    $utilisateur = User::factory()->create();
    BodyPartMeasurement::factory()->create(['user_id' => $utilisateur->id, 'part' => 'Taille', 'value' => 91, 'unit' => 'cm', 'measured_at' => '2026-09-02']);
    $objectif = Goal::factory()->create([
        'user_id' => $utilisateur->id,
        'type' => GoalType::Measurement,
        'measurement_type' => 'Waist',
        'start_value' => 90,
        'current_value' => 90,
        'target_value' => 80,
        'completed_at' => null,
    ]);

    return [$utilisateur, $objectif];
}

it('range sous la clef la mesure de la pastille quand un objectif suit la clef, même sans mesure rangée sous elle', function (): void {
    [$utilisateur, $objectif] = compteDontLObjectifSuitLaClefSansMesure();

    $this->actingAs($utilisateur)
        ->post(route('body-parts.store'), ['part' => 'Taille', 'value' => 84, 'unit' => 'cm', 'measured_at' => '2026-10-05'])
        ->assertSessionHasNoErrors();

    expect(mesuresDuCompteParPartie($utilisateur))->toBe(['Taille' => 1, 'Waist' => 1])
        ->and((float) $objectif->fresh()?->current_value)->toBe(84.0);

    $this->actingAs($utilisateur)
        ->get(route('body-parts.index'))
        ->assertInertia(fn (AssertableInertia $liste): AssertableInertia => $liste
            ->has('latestMeasurements', 2)
            ->where('latestMeasurements.0.part', 'Taille')
            ->where('latestMeasurements.0.label', 'Taille (saisie libre)')
            ->where('latestMeasurements.1.part', 'Waist')
            ->where('latestMeasurements.1.label', 'Taille')
            ->where('latestMeasurements.1.current', 84));
});

it('garde la mesure ajoutée depuis la page de la partie saisie à la main dans cet historique, même quand un objectif suit la clef', function (): void {
    [$utilisateur, $objectif] = compteDontLObjectifSuitLaClefSansMesure();

    $this->actingAs($utilisateur)
        ->post(route('body-parts.store'), ['part' => 'Taille', 'keep_part_name' => true, 'value' => 89, 'unit' => 'cm', 'measured_at' => '2026-10-05'])
        ->assertSessionHasNoErrors();

    expect(mesuresDuCompteParPartie($utilisateur))->toBe(['Taille' => 2])
        ->and((float) $objectif->fresh()?->current_value)->toBe(90.0);
});

it('ne suit la clef que par un objectif de mensuration du compte lui-même', function (): void {
    $utilisateur = User::factory()->create();
    BodyPartMeasurement::factory()->create(['user_id' => $utilisateur->id, 'part' => 'Taille', 'value' => 91, 'unit' => 'cm', 'measured_at' => '2026-09-02']);
    Goal::factory()->create(['user_id' => User::factory()->create()->id, 'type' => GoalType::Measurement, 'measurement_type' => 'Waist', 'start_value' => 90, 'target_value' => 80]);

    $this->actingAs($utilisateur)
        ->post(route('body-parts.store'), ['part' => 'Taille', 'value' => 88, 'unit' => 'cm', 'measured_at' => '2026-10-05'])
        ->assertSessionHasNoErrors();

    expect(mesuresDuCompteParPartie($utilisateur))->toBe(['Taille' => 2]);
});

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
