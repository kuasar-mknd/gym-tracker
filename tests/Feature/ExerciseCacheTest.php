<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExerciseCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_store_invalidates_cache(): void
    {
        $user = User::factory()->create();
        $cacheKey = "exercises_list_{$user->id}";
        Cache::put($cacheKey, 'some cached data', 3600);

        $this->actingAs($user)->post('/exercises', [
            'name' => 'New Exercise',
            'type' => 'strength',
            'category' => 'Pectoraux',
        ]);

        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated after creating an exercise via Web');
    }

    public function test_web_update_invalidates_cache(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);
        $cacheKey = "exercises_list_{$user->id}";
        Cache::put($cacheKey, 'some cached data', 3600);

        $this->actingAs($user)->put("/exercises/{$exercise->id}", [
            'name' => 'Updated Name',
            'type' => 'strength',
            'category' => 'Pectoraux',
        ]);

        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated after updating an exercise via Web');
    }

    public function test_web_destroy_invalidates_cache(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);
        $cacheKey = "exercises_list_{$user->id}";
        Cache::put($cacheKey, 'some cached data', 3600);

        $this->actingAs($user)->delete("/exercises/{$exercise->id}");

        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated after deleting an exercise via Web');
    }

    public function test_api_store_invalidates_cache(): void
    {
        $user = User::factory()->create();
        $cacheKey = "exercises_list_{$user->id}";
        Cache::put($cacheKey, 'some cached data', 3600);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/exercises', [
            'name' => 'New API Exercise',
            'type' => 'strength',
            'category' => 'Pectoraux',
        ]);

        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated after creating an exercise via API');
    }

    public function test_api_update_invalidates_cache(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);
        $cacheKey = "exercises_list_{$user->id}";
        Cache::put($cacheKey, 'some cached data', 3600);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/exercises/{$exercise->id}", [
            'name' => 'Updated API Name',
        ]);

        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated after updating an exercise via API');
    }

    public function test_api_destroy_invalidates_cache(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);
        $cacheKey = "exercises_list_{$user->id}";
        Cache::put($cacheKey, 'some cached data', 3600);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/exercises/{$exercise->id}");

        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated after deleting an exercise via API');
    }
}
