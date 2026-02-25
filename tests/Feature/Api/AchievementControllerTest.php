<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Authenticated User', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    test('user can list achievements', function (): void {
        Achievement::factory()->count(3)->create();

        $response = getJson(route('api.v1.achievements.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data')
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

    test('user can view a specific achievement', function (): void {
        $achievement = Achievement::factory()->create();

        $response = getJson(route('api.v1.achievements.show', $achievement));

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $achievement->id,
                'slug' => $achievement->slug,
            ]);
    });

    test('user cannot create achievement', function (): void {
        $data = [
            'slug' => 'test-achievement',
            'name' => 'Test Achievement',
            'description' => 'Description',
            'icon' => '🏆',
            'type' => 'streak',
            'threshold' => 10,
            'category' => 'general',
        ];

        $response = postJson(route('api.v1.achievements.store'), $data);

        $response->assertForbidden();
    });

    test('user cannot update achievement', function (): void {
        $achievement = Achievement::factory()->create();

        $response = putJson(route('api.v1.achievements.update', $achievement), [
            'name' => 'Updated Name',
        ]);

        $response->assertForbidden();
    });

    test('user cannot delete achievement', function (): void {
        $achievement = Achievement::factory()->create();

        $response = deleteJson(route('api.v1.achievements.destroy', $achievement));

        $response->assertForbidden();
    });
});

describe('Unauthenticated User', function (): void {
    test('guest cannot list achievements', function (): void {
        $response = getJson(route('api.v1.achievements.index'));
        $response->assertUnauthorized();
    });

    test('guest cannot view achievement', function (): void {
        $achievement = Achievement::factory()->create();
        $response = getJson(route('api.v1.achievements.show', $achievement));
        $response->assertUnauthorized();
    });
});
