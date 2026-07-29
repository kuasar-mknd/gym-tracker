<?php

declare(strict_types=1);

use App\Actions\Tools\Concerns\CalculatesMacros;

$testClass = new class()
{
    use CalculatesMacros;

    public function testCalculation(array $data): array
    {
        return $this->performCalculation($data);
    }
};

it('calculates macros correctly for various scenarios', function (array $input, array $expected) use ($testClass): void {
    $result = $testClass->testCalculation($input);

    // Convert expected values to float for precise matching as PHP's max/round might return int
    expect($result)->toEqual($expected);
})->with([
    'male maintenance' => [
        'input' => [
            'gender' => 'male',
            'age' => 30,
            'height' => 180,
            'weight' => 80,
            'activity_level' => 'sedentary',
            'goal' => 'maintain',
        ],
        'expected' => [
            'tdee' => 2136.0,
            'target_calories' => 2136.0,
            'protein' => 160.0,
            'fat' => 72.0,
            'carbs' => 212.0,
        ],
    ],
    'female cut moderate activity' => [
        'input' => [
            'gender' => 'female',
            'age' => 25,
            'height' => 160,
            'weight' => 60,
            'activity_level' => 'moderate',
            'goal' => 'cut',
        ],
        'expected' => [
            'tdee' => 2037.0,
            'target_calories' => 1537.0,
            'protein' => 120.0,
            'fat' => 54.0,
            'carbs' => 143.0,
        ],
    ],
    'female cut hitting calorie minimum' => [
        'input' => [
            'gender' => 'female',
            'age' => 40,
            'height' => 150,
            'weight' => 45,
            'activity_level' => 'sedentary',
            'goal' => 'cut',
        ],
        'expected' => [
            'tdee' => 1232.0,
            'target_calories' => 1200.0,
            'protein' => 90.0,
            'fat' => 41.0,
            'carbs' => 118.0,
        ],
    ],
    'male cut heavy weight' => [
        'input' => [
            'gender' => 'male',
            'age' => 20,
            'height' => 190,
            'weight' => 120,
            'activity_level' => 'sedentary',
            'goal' => 'cut',
        ],
        'expected' => [
            'tdee' => 2751.0,
            'target_calories' => 2251.0,
            'protein' => 240.0,
            'fat' => 108.0,
            'carbs' => 80.0,
        ],
    ],
    'extreme cut with negative initial remaining calories' => [
        'input' => [
            'gender' => 'female',
            'age' => 50,
            'height' => 150,
            'weight' => 90,
            'activity_level' => 'sedentary',
            'goal' => 'cut',
        ],
        'expected' => [
            'tdee' => 1712.0,
            'target_calories' => 1212.0,
            'protein' => 180.0,
            'fat' => 55.0, // fallback logic triggered
            'carbs' => 0.0,
        ],
    ],
    'male bulk high activity' => [
        'input' => [
            'gender' => 'male',
            'age' => 25,
            'height' => 180,
            'weight' => 80,
            'activity_level' => 'extra',
            'goal' => 'bulk',
        ],
        'expected' => [
            'tdee' => 3430.0,
            'target_calories' => 3730.0,
            'protein' => 160.0,
            'fat' => 72.0,
            'carbs' => 611.0,
        ],
    ],
]);
