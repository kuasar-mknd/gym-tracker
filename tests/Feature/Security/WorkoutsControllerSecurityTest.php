<?php

namespace Tests\Feature\Security;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkoutsControllerSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_cannot_see_other_users_private_exercises_in_workout_show(): void
    {
        // 1. Create two users
        $victim = User::factory()->create();
        $attacker = User::factory()->create();

        // 2. Victim creates a private exercise
        Exercise::factory()->create([
            'user_id' => $victim->id,
            'name' => 'SUPER SECRET TECHNIQUE',
        ]);

        // 3. System exercise (visible to everyone)
        Exercise::factory()->create([
            'user_id' => null,
            'name' => 'Public Pushup',
        ]);

        // 4. Attacker creates a workout and views it
        $workout = Workout::factory()->create(['user_id' => $attacker->id]);

        // Clear cache to ensure we hit the controller logic
        Cache::flush();

        $response = $this->actingAs($attacker)->get(route('workouts.show', $workout));

        // 5. Assert that the secret exercise is NOT in the exercises list
        $response->assertOk();

        $exercises = $response->inertiaProps()['exercises'];
        $names = collect($exercises)->pluck('name')->toArray();

        $this->assertContains('Public Pushup', $names);
        $this->assertNotContains('SUPER SECRET TECHNIQUE', $names, 'User should not see other users private exercises');
    }

    public function test_cache_separation_between_users(): void
    {
        // Test that if Victim loads the page first (populating cache),
        // Attacker doesn't get Victim's exercises from cache.

        $victim = User::factory()->create();
        $attacker = User::factory()->create();

        Exercise::factory()->create([
            'user_id' => $victim->id,
            'name' => 'Victim Private Move',
        ]);

        $workoutVictim = Workout::factory()->create(['user_id' => $victim->id]);
        $workoutAttacker = Workout::factory()->create(['user_id' => $attacker->id]);

        Cache::flush();

        // Victim loads page first
        $this->actingAs($victim)->get(route('workouts.show', $workoutVictim));

        // Attacker loads page
        $response = $this->actingAs($attacker)->get(route('workouts.show', $workoutAttacker));

        $exercises = $response->inertiaProps()['exercises'];
        $names = collect($exercises)->pluck('name')->toArray();

        $this->assertNotContains('Victim Private Move', $names, 'User should not see cached private exercises from other users');
    }
}
