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
