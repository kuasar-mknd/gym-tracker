<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserAchievement>
 */
class UserAchievementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'achievement_id' => Achievement::factory(),
            'achieved_at' => now(),
        ];
    }
}
