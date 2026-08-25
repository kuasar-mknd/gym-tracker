<?php

declare(strict_types=1);

use App\Actions\Measurements\FetchBodyPartMeasurementsIndexAction;
use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Support\Carbon;

it('fetches latest measurements correctly utilizing window functions', function (): void {
    $user = User::factory()->create();
    $action = app(FetchBodyPartMeasurementsIndexAction::class);

    // Chest: 3 entries to ensure it only takes top 2
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 100,
        'measured_at' => Carbon::now()->subDays(10),
    ]);
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 105,
        'measured_at' => Carbon::now()->subDays(5),
    ]);
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 108,
        'measured_at' => Carbon::now(),
    ]);

    // Waist: 1 entry to test diff when no previous exists
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Waist',
        'value' => 80,
        'measured_at' => Carbon::now(),
    ]);

    // Test negative difference (losing weight/size)
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Hips',
        'value' => 95,
        'measured_at' => Carbon::now()->subDays(5),
    ]);
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Hips',
        'value' => 90,
        'measured_at' => Carbon::now(),
    ]);

    // Another user's measurements to ensure they are not fetched
    $otherUser = User::factory()->create();
    BodyPartMeasurement::factory()->create([
        'user_id' => $otherUser->id,
        'part' => 'Chest',
        'value' => 120,
        'measured_at' => Carbon::now(),
    ]);

    $result = $action->execute($user);

    expect($result)->toHaveKey('commonParts')
        ->and($result['commonParts'])->toBeArray()->not->toBeEmpty()
        ->and($result['commonParts'])->toContain('Chest', 'Waist', 'Hips', 'Biceps L');

    expect($result)->toHaveKey('latestMeasurements')
        ->and($result['latestMeasurements'])->toHaveCount(3); // Chest, Waist, Hips

    $chestData = $result['latestMeasurements']->firstWhere('part', 'Chest');
    expect($chestData)->not->toBeNull()
        ->and($chestData['current'])->toBe(108.0)
        ->and($chestData['diff'])->toBe(3.0) // 108 - 105
        ->and($chestData['date'])->toBe(Carbon::now()->format('Y-m-d'));

    $waistData = $result['latestMeasurements']->firstWhere('part', 'Waist');
    expect($waistData)->not->toBeNull()
        ->and($waistData['current'])->toBe(80.0)
        ->and($waistData['diff'])->toBe(0.0) // No previous measurement
        ->and($waistData['date'])->toBe(Carbon::now()->format('Y-m-d'));

    $hipsData = $result['latestMeasurements']->firstWhere('part', 'Hips');
    expect($hipsData)->not->toBeNull()
        ->and($hipsData['current'])->toBe(90.0)
        ->and($hipsData['diff'])->toBe(-5.0) // 90 - 95
        ->and($hipsData['date'])->toBe(Carbon::now()->format('Y-m-d'));
});

it('returns empty latest measurements when user has no measurements', function (): void {
    $user = User::factory()->create();
    $action = app(FetchBodyPartMeasurementsIndexAction::class);

    $result = $action->execute($user);

    expect($result['latestMeasurements'])->toBeEmpty();
});

/*
 * Ce que les tests ci-dessus laissaient passer.
 *
 * Le fichier etait execute mais peu verifie : 14 des 37 reecritures de l'action
 * passaient la suite au vert. Les trois tests qui suivent ferment celles qui se
 * voient depuis la sortie de l'action.
 */

it('rend les treize parties courantes, dans leur ordre', function (): void {
    $user = User::factory()->create();

    $result = app(FetchBodyPartMeasurementsIndexAction::class)->execute($user);

    /*
     * `toContain('Chest', 'Waist', 'Hips', 'Biceps L')` ne regardait que quatre
     * entrees sur treize : supprimer n'importe laquelle des neuf autres — le
     * cou, les epaules, un biceps, un avant-bras, une cuisse, un mollet — ne
     * faisait echouer aucun test, alors que la partie disparait des raccourcis
     * du formulaire de saisie (`v-for="part in commonParts"`). L'egalite
     * stricte tient la liste entiere et son ordre, qui est celui dans lequel la
     * page les propose.
     */
    expect($result['commonParts'])->toBe([
        'Neck',
        'Shoulders',
        'Chest',
        'Biceps L',
        'Biceps R',
        'Forearm L',
        'Forearm R',
        'Waist',
        'Hips',
        'Thigh L',
        'Thigh R',
        'Calf L',
        'Calf R',
    ]);
});

it('decrit chaque mesure par cinq cles, unite comprise', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Neck',
        'value' => 38.50,
        // Pose, et different du defaut 'cm' de la colonne : une unite tiree de
        // la fabrique ne dirait rien, et le defaut masquerait un oubli.
        'unit' => 'in',
        'measured_at' => Carbon::parse('2026-06-15'),
    ]);

    /** @var array{part: string, current: float, unit: string, date: non-falsy-string, diff: float} $mesure */
    $mesure = app(FetchBodyPartMeasurementsIndexAction::class)->execute($user)['latestMeasurements']->firstOrFail();

    /*
     * Aucune assertion ne portait sur `unit` : la carte pouvait perdre son
     * unite sans qu'un test bronche, et 38,5 sans unite ne veut rien dire.
     * Comparer les cles exactes tient les cinq d'un coup.
     */
    expect(array_keys($mesure))->toBe(['part', 'current', 'unit', 'date', 'diff']);

    expect($mesure['part'])->toBe('Neck')
        ->and($mesure['unit'])->toBe('in')
        ->and($mesure['current'])->toBe(38.5)
        ->and($mesure['date'])->toBe('2026-06-15')
        ->and($mesure['diff'])->toBe(0.0);
});

it('arrondit l ecart au centieme', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();

    // Deux dates distinctes : `ORDER BY measured_at DESC` sur une colonne
    // `date` ne departagerait pas deux mesures du meme jour.
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Waist',
        'value' => 82.75,
        'measured_at' => Carbon::parse('2026-06-10'),
    ]);
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Waist',
        'value' => 86.00,
        'measured_at' => Carbon::parse('2026-06-15'),
    ]);

    /** @var array{part: string, current: float, unit: string, date: non-falsy-string, diff: float} $mesure */
    $mesure = app(FetchBodyPartMeasurementsIndexAction::class)->execute($user)['latestMeasurements']->firstOrFail();

    /*
     * Les ecarts testes jusqu'ici (3, 0, -5) etaient entiers : arrondir au
     * dixieme au lieu du centieme ne changeait rien, donc rien ne fixait la
     * precision annoncee. 3,25 la fixe — au dixieme, l'ecart deviendrait 3,3.
     */
    expect($mesure['diff'])->toBe(3.25)->toBeFloat();
});
