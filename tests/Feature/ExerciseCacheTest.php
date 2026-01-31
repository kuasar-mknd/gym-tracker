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

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_creating_exercise_invalidates_cache(): void
    {
        $cacheKey = "exercises_list_{$this->user->id}";
        Cache::put($cacheKey, 'some cached data', 3600);
        $this->assertTrue(Cache::has($cacheKey), 'Cache key should exist before operation');

        $this->actingAs($this->user)->post('/exercises', [
            'name' => 'New Exercise',
            'type' => 'strength',
            'category' => 'Pectoraux',
        ]);

        $this->assertFalse(Cache::has($cacheKey), 'Cache key should be invalidated after create');
    }

    public function test_updating_exercise_invalidates_cache(): void
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);
        $cacheKey = "exercises_list_{$this->user->id}";
        Cache::put($cacheKey, 'some cached data', 3600);
        $this->assertTrue(Cache::has($cacheKey), 'Cache key should exist before operation');

        $this->actingAs($this->user)->put("/exercises/{$exercise->id}", [
            'name' => 'Updated Exercise',
            'type' => 'strength',
            'category' => 'Pectoraux',
        ]);

        $this->assertFalse(Cache::has($cacheKey), 'Cache key should be invalidated after update');
    }

    public function test_deleting_exercise_invalidates_cache(): void
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);
        $cacheKey = "exercises_list_{$this->user->id}";
        Cache::put($cacheKey, 'some cached data', 3600);
        $this->assertTrue(Cache::has($cacheKey), 'Cache key should exist before operation');

        $this->actingAs($this->user)->delete("/exercises/{$exercise->id}");

        $this->assertFalse(Cache::has($cacheKey), 'Cache key should be invalidated after delete');
    }

    public function test_api_creating_exercise_invalidates_cache(): void
    {
        $cacheKey = "exercises_list_{$this->user->id}";
        Cache::put($cacheKey, 'some cached data', 3600);
        $this->assertTrue(Cache::has($cacheKey), 'Cache key should exist before operation');

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/exercises', [
            'name' => 'New API Exercise',
            'type' => 'strength',
            'category' => 'Test',
        ]);

        $this->assertFalse(Cache::has($cacheKey), 'Cache key should be invalidated after API create');
    }
}
