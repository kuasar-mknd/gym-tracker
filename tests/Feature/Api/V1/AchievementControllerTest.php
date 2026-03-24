<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

describe('GET /api/v1/achievements', function (): void {
    it('returns a paginated list of achievements', function (): void {
        Achievement::factory()->count(15)->create();

        $response = actingAs($this->user)
            ->getJson('/api/v1/achievements');

        $response->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'slug',
                        'name',
                        'description',
                        'icon',
                        'type',
                        'threshold',
                        'category',
                    ],
                ],
                'links',
                'meta',
            ]);
    });

    it('requires authentication to list achievements', function (): void {
        $response = getJson('/api/v1/achievements');
        $response->assertUnauthorized();
    });
});

describe('POST /api/v1/achievements', function (): void {
    it('creates an achievement when authorized', function (): void {
        Gate::before(fn (): true => true);

        $payload = [
            'slug' => 'test-achievement',
            'name' => 'Test Achievement',
            'description' => 'A test achievement description',
            'icon' => '🏆',
            'type' => 'workout_count',
            'threshold' => 10,
            'category' => 'beginner',
        ];

        $response = actingAs($this->user)
            ->postJson('/api/v1/achievements', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'test-achievement')
            ->assertJsonPath('data.name', 'Test Achievement');

        assertDatabaseHas('achievements', [
            'slug' => 'test-achievement',
            'name' => 'Test Achievement',
        ]);
    });

    it('returns validation errors for invalid data', function (): void {
        Gate::before(fn (): true => true);

        $response = actingAs($this->user)
            ->postJson('/api/v1/achievements', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'slug', 'name', 'description', 'icon', 'type', 'threshold', 'category',
            ]);
    });

    it('prevents unauthorized users from creating achievements', function (): void {
        $payload = [
            'slug' => 'test-achievement',
            'name' => 'Test Achievement',
            'description' => 'A test achievement description',
            'icon' => '🏆',
            'type' => 'workout_count',
            'threshold' => 10,
            'category' => 'beginner',
        ];

        $response = actingAs($this->user)
            ->postJson('/api/v1/achievements', $payload);

        $response->assertForbidden();
    });

    it('requires authentication', function (): void {
        $response = postJson('/api/v1/achievements', []);
        $response->assertUnauthorized();
    });
});

describe('GET /api/v1/achievements/{achievement}', function (): void {
    it('returns the specified achievement', function (): void {
        $achievement = Achievement::factory()->create();

        $response = actingAs($this->user)
            ->getJson("/api/v1/achievements/{$achievement->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $achievement->id)
            ->assertJsonPath('data.slug', $achievement->slug)
            ->assertJsonPath('data.name', $achievement->name);
    });

    it('returns 404 for a non-existent achievement', function (): void {
        $response = actingAs($this->user)
            ->getJson('/api/v1/achievements/99999');

        $response->assertNotFound();
    });

    it('requires authentication to view a specific achievement', function (): void {
        $achievement = Achievement::factory()->create();

        $response = getJson("/api/v1/achievements/{$achievement->id}");
        $response->assertUnauthorized();
    });
});

describe('PUT /api/v1/achievements/{achievement}', function (): void {
    it('updates an achievement when authorized', function (): void {
        Gate::before(fn (): true => true);

        $achievement = Achievement::factory()->create([
            'name' => 'Old Name',
        ]);

        $payload = [
            'name' => 'New Name',
        ];

        $response = actingAs($this->user)
            ->putJson("/api/v1/achievements/{$achievement->id}", $payload);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        assertDatabaseHas('achievements', [
            'id' => $achievement->id,
            'name' => 'New Name',
        ]);
    });

    it('returns validation errors for invalid update data', function (): void {
        Gate::before(fn (): true => true);

        $achievement = Achievement::factory()->create();

        $response = actingAs($this->user)
            ->putJson("/api/v1/achievements/{$achievement->id}", [
                'threshold' => -5,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['threshold']);
    });

    it('prevents unauthorized users from updating achievements', function (): void {
        $achievement = Achievement::factory()->create();

        $response = actingAs($this->user)
            ->putJson("/api/v1/achievements/{$achievement->id}", [
                'name' => 'Unauthorized Name',
            ]);

        $response->assertForbidden();

        assertDatabaseMissing('achievements', [
            'id' => $achievement->id,
            'name' => 'Unauthorized Name',
        ]);
    });

    it('requires authentication to update', function (): void {
        $achievement = Achievement::factory()->create();

        $response = putJson("/api/v1/achievements/{$achievement->id}", [
            'name' => 'New Name',
        ]);

        $response->assertUnauthorized();
    });
});

describe('DELETE /api/v1/achievements/{achievement}', function (): void {
    it('deletes an achievement when authorized', function (): void {
        Gate::before(fn (): true => true);

        $achievement = Achievement::factory()->create();

        $response = actingAs($this->user)
            ->deleteJson("/api/v1/achievements/{$achievement->id}");

        $response->assertNoContent();

        assertDatabaseMissing('achievements', [
            'id' => $achievement->id,
        ]);
    });

    it('prevents unauthorized users from deleting achievements', function (): void {
        $achievement = Achievement::factory()->create();

        $response = actingAs($this->user)
            ->deleteJson("/api/v1/achievements/{$achievement->id}");

        $response->assertForbidden();

        assertDatabaseHas('achievements', [
            'id' => $achievement->id,
        ]);
    });

    it('requires authentication to delete', function (): void {
        $achievement = Achievement::factory()->create();

        $response = deleteJson("/api/v1/achievements/{$achievement->id}");
        $response->assertUnauthorized();
    });

    it('returns 404 for a non-existent achievement on delete', function (): void {
        Gate::before(fn (): true => true);

        $response = actingAs($this->user)
            ->deleteJson('/api/v1/achievements/99999');

        $response->assertNotFound();
    });
});
