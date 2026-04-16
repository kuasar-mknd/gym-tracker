<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkoutStoreAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_endpoint_enforces_create_policy(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Gate::before(function ($user, $ability) {
            if ($ability === 'create') {
                return false;
            }
        });

        $data = [
            'name' => 'Should fail',
            'started_at' => now()->toIso8601String(),
        ];

        $response = $this->postJson(route('api.v1.workouts.store'), $data);

        $response->assertForbidden();
    }

    public function test_store_endpoint_allows_creation_when_policy_permits(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Gate::before(function ($user, $ability) {
            if ($ability === 'create') {
                return true;
            }
        });

        $data = [
            'name' => 'Should succeed',
            'started_at' => now()->toIso8601String(),
        ];

        $response = $this->postJson(route('api.v1.workouts.store'), $data);

        $response->assertCreated();
    }
}
