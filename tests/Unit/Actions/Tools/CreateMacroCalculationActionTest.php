<?php

declare(strict_types=1);

use App\Actions\Tools\CreateMacroCalculationAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('execute maps activity level to multiplier and creates calculation', function (string $activityLevel, float $expectedMultiplier): void {
    $user = User::factory()->create();
    $action = app(CreateMacroCalculationAction::class);

    $data = [
        'gender' => 'male',
        'age' => 30,
        'height' => 180.0,
        'weight' => 80.0,
        'activity_level' => $activityLevel,
        'goal' => 'maintain',
    ];

    $calculation = $action->execute($user, $data);

    // The model casts activity_level to decimal:2, so we expect rounded value from the property
    $roundedMultiplier = round($expectedMultiplier, 2);
    expect((float) $calculation->activity_level)->toEqual($roundedMultiplier);
    expect($calculation->user_id)->toBe($user->id);

    // Check that calculated fields are present
    expect($calculation->tdee)->toBeGreaterThan(0);
    expect($calculation->target_calories)->toBeGreaterThan(0);
    expect($calculation->protein)->toBeGreaterThan(0);
    expect($calculation->fat)->toBeGreaterThan(0);
    expect($calculation->carbs)->toBeGreaterThan(0);

    // Verify database record using the raw multiplier
    $this->assertDatabaseHas('macro_calculations', [
        'id' => $calculation->id,
        'user_id' => $user->id,
        'activity_level' => $expectedMultiplier,
        'gender' => 'male',
        'age' => 30,
        'height' => 180.0,
        'weight' => 80.0,
        'goal' => 'maintain',
    ]);
})->with([
    ['sedentary', 1.20],
    ['light', 1.375], // Raw multiplier before decimal:2 casting
    ['moderate', 1.55],
    ['very', 1.725],
    ['extra', 1.90],
]);

test('execute correctly calculates and merges macros into model', function (): void {
    $user = User::factory()->create();
    $action = app(CreateMacroCalculationAction::class);

    $data = [
        'gender' => 'male',
        'age' => 30,
        'height' => 180.0,
        'weight' => 80.0,
        'activity_level' => 'moderate',
        'goal' => 'cut',
    ];

    $calculation = $action->execute($user, $data);

    // Assert results based on performCalculation behavior
    expect($calculation->tdee)->toBe(2759);
    expect($calculation->target_calories)->toBe(2259);
    expect($calculation->protein)->toBe(160);
    expect($calculation->fat)->toBe(72);
    expect($calculation->carbs)->toBe(243);
    expect($calculation->goal)->toBe('cut');
    expect($calculation->gender)->toBe('male');
});
