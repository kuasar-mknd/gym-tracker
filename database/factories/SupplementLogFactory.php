<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SupplementLog>
 */
class SupplementLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\SupplementLog>
     */
    protected $model = SupplementLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'supplement_id' => Supplement::factory(),
            'quantity' => $this->faker->numberBetween(1, 3),
            'consumed_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
