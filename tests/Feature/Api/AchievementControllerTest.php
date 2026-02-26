<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

test('authenticated user can list achievements', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Achievement::factory()->count(3)->create();

    $response = getJson(route('api.v1.achievements.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('authenticated user can show an achievement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $achievement = Achievement::factory()->create();

    $response = getJson(route('api.v1.achievements.show', $achievement));

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $achievement->id,
            'name' => $achievement->name,
            'slug' => $achievement->slug,
        ]);
});

test('authenticated user without permission cannot create achievement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $data = [
        'slug' => 'test-achievement',
        'name' => 'Test Achievement',
        'description' => 'Description',
        'icon' => 'icon-name',
        'type' => 'type',
        'threshold' => 10,
        'category' => 'category',
    ];

    $response = postJson(route('api.v1.achievements.store'), $data);

    $response->assertForbidden();
});

test('authenticated user without permission cannot update achievement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $achievement = Achievement::factory()->create();

    $data = [
        'name' => 'Updated Name',
        'slug' => $achievement->slug,
        'description' => $achievement->description,
        'icon' => $achievement->icon,
        'type' => $achievement->type,
        'threshold' => $achievement->threshold,
        'category' => $achievement->category,
    ];

    $response = putJson(route('api.v1.achievements.update', $achievement), $data);

    $response->assertForbidden();
});

test('authenticated user without permission cannot delete achievement', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $achievement = Achievement::factory()->create();

    $response = deleteJson(route('api.v1.achievements.destroy', $achievement));

    $response->assertForbidden();
});

test('unauthenticated user cannot list achievements', function (): void {
    getJson(route('api.v1.achievements.index'))
        ->assertUnauthorized();
});
