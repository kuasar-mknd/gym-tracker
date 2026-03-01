<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Achievement;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

describe('Achievement API', function (): void {
    it('can list achievements', function (): void {
        Achievement::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/achievements');

        $response->assertOk()
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
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ]);
    });

    it('can show an achievement', function (): void {
        $achievement = Achievement::factory()->create();

        $response = $this->getJson("/api/v1/achievements/{$achievement->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $achievement->id)
            ->assertJsonPath('data.name', $achievement->name);
    });

    it('standard user cannot create achievement', function (): void {
        $data = [
            'slug' => 'new-achievement',
            'name' => 'New Achievement',
            'description' => 'A great new achievement',
            'icon' => 'star',
            'type' => 'count',
            'threshold' => 10,
            'category' => 'general',
        ];

        $response = $this->postJson('/api/v1/achievements', $data);

        $response->assertForbidden();
    });

    it('standard user cannot update achievement', function (): void {
        $achievement = Achievement::factory()->create();

        $data = [
            'name' => 'Updated Name',
        ];

        $response = $this->putJson("/api/v1/achievements/{$achievement->id}", $data);

        $response->assertForbidden();
    });

    it('standard user cannot delete achievement', function (): void {
        $achievement = Achievement::factory()->create();

        $response = $this->deleteJson("/api/v1/achievements/{$achievement->id}");

        $response->assertForbidden();
    });
});
