<?php

declare(strict_types=1);

use App\Actions\Tools\Concerns\CalculatesMacros;

test('calculates macros correctly when remaining calories are less than zero', function (): void {
    $calculator = new class()
    {
        use CalculatesMacros;

        /**
         * @return array<string, float|int>
         */
        public function testCalculateMacros(float $targetCalories, float $weight): array
        {
            return $this->calculateMacros($targetCalories, $weight);
        }
    };

    $result = $calculator->testCalculateMacros(1200, 120);

    // Using loosely checking or specific types
    expect($result['protein'])->toEqual(240);
    expect($result['fat'])->toEqual(30);
    expect($result['carbs'])->toEqual(0);
});

test('calculates macros correctly when remaining calories are positive', function (): void {
    $calculator = new class()
    {
        use CalculatesMacros;

        /**
         * @return array<string, float|int>
         */
        public function testCalculateMacros(float $targetCalories, float $weight): array
        {
            return $this->calculateMacros($targetCalories, $weight);
        }
    };

    $result = $calculator->testCalculateMacros(2500, 80);

    expect($result['protein'])->toEqual(160);
    expect($result['fat'])->toEqual(72);
    expect($result['carbs'])->toEqual(303);
});
