<?php

declare(strict_types=1);

use App\Actions\Tools\UpdateMacroCalculationAction;
use App\Models\MacroCalculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('updates macro calculation and maps multiplier correctly', function (): void {
    $user = User::factory()->create();

    // Create initial calculation
    $macroCalculation = MacroCalculation::create([
        'user_id' => $user->id,
        'gender' => 'female',
        'age' => 30,
        'height' => 165,
        'weight' => 60,
        'activity_level' => 1.2, // sedentary
        'goal' => 'maintain',
        'tdee' => 1500,
        'target_calories' => 1500,
        'protein' => 120,
        'fat' => 54,
        'carbs' => 134,
    ]);

    $action = new UpdateMacroCalculationAction();

    $data = [
        'gender' => 'male',
        'age' => 25,
        'height' => 180,
        'weight' => 80,
        'activity_level' => 'moderate', // label to be mapped
        'goal' => 'bulk',
    ];

    $updated = $action->execute($macroCalculation, $data);

    // Verify correct mapping
    expect((float) $updated->activity_level)->toBe(1.55); // moderate multiplier

    // Verify updated basic info
    expect($updated->gender)->toBe('male');
    expect($updated->age)->toBe(25);
    expect((float) $updated->height)->toBe(180.0);
    expect((float) $updated->weight)->toBe(80.0);
    expect($updated->goal)->toBe('bulk');

    // Verify calculation logic (BMR: 10*80 + 6.25*180 - 5*25 + 5 = 800 + 1125 - 125 + 5 = 1805)
    // TDEE: 1805 * 1.55 = 2797.75 -> 2798
    // Target (bulk): 2798 + 300 = 3098
    expect((int) $updated->tdee)->toBe(2798);
    expect((int) $updated->target_calories)->toBe(3098);
});
