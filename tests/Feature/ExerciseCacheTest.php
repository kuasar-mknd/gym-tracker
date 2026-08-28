<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ces cas posaient une valeur sous `exercises_list_{id}` — une clef sans
 * revision, que l'application n'ecrit jamais — puis verifiaient qu'elle avait
 * disparu. Seul un `Cache::forget` ecrit pour eux les faisait passer.
 *
 * Ils lisent maintenant la liste que les pages lisent, et verifient qu'elle
 * porte bien la modification.
 */
class ExerciseCacheTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function listeCachee(User $user): array
    {
        return Exercise::getCachedForUser($user->id)
            ->map(fn (Exercise $exercice): string => (string) $exercice->name)
            ->values()
            ->all();
    }

    public function test_web_store_invalidates_cache(): void
    {
        $user = User::factory()->create();
        $this->listeCachee($user);

        $this->actingAs($user)->post('/exercises', [
            'name' => 'New Exercise',
            'type' => 'strength',
            'category' => 'Pectoraux',
        ]);

        $this->assertContains('New Exercise', $this->listeCachee($user));
    }

    public function test_web_update_invalidates_cache(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);
        $this->listeCachee($user);

        $this->actingAs($user)->put("/exercises/{$exercise->id}", [
            'name' => 'Updated Name',
            'type' => 'strength',
            'category' => 'Pectoraux',
        ]);

        $this->assertContains('Updated Name', $this->listeCachee($user));
    }

    public function test_web_destroy_invalidates_cache(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);
        $this->listeCachee($user);

        $this->actingAs($user)->delete("/exercises/{$exercise->id}");

        $this->assertNotContains($exercise->name, $this->listeCachee($user));
    }

    public function test_api_store_invalidates_cache(): void
    {
        $user = User::factory()->create();
        $this->listeCachee($user);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/exercises', [
            'name' => 'New API Exercise',
            'type' => 'strength',
            'category' => 'Pectoraux',
        ]);

        $this->assertContains('New API Exercise', $this->listeCachee($user));
    }

    public function test_api_update_invalidates_cache(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);
        $this->listeCachee($user);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/exercises/{$exercise->id}", [
            'name' => 'Updated API Name',
        ]);

        $this->assertContains('Updated API Name', $this->listeCachee($user));
    }

    public function test_api_destroy_invalidates_cache(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);
        $this->listeCachee($user);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/exercises/{$exercise->id}");

        $this->assertNotContains($exercise->name, $this->listeCachee($user));
    }
}
