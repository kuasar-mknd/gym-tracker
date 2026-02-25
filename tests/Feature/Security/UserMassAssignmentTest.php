<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_stats_are_protected_against_mass_assignment(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'current_streak' => 1,
            'longest_streak' => 5,
            'last_workout_at' => Carbon::parse('2023-01-01 12:00:00'),
        ])->save();

        $payload = [
            'name' => 'Hacker Name',
            'current_streak' => 999,
            'longest_streak' => 999,
            'last_workout_at' => '2025-01-01 12:00:00',
        ];

        // Expect MassAssignmentException because strict mode is enabled in tests
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);

        // Simulate mass assignment
        $user->update($payload);
    }
}
