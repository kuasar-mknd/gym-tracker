<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('authenticated user can list achievements', function (): void {
    $user = User::factory()->create();
    Achievement::factory()->count(3)->create();

    actingAs($user)
        ->getJson(route('api.v1.achievements.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('authenticated user can list achievements with unlock status', function (): void {
    $user = User::factory()->create();
    $unlocked = Achievement::factory()->create();
    $locked = Achievement::factory()->create();

    $user->achievements()->attach($unlocked, ['achieved_at' => now()]);

    $response = actingAs($user)
        ->getJson(route('api.v1.achievements.index'));

    $response->assertOk();

    $data = $response->json('data');
    $unlockedItem = collect($data)->firstWhere('id', $unlocked->id);
    $lockedItem = collect($data)->firstWhere('id', $locked->id);

    expect($unlockedItem['is_unlocked'])->toBeTrue()
        ->and($unlockedItem['unlocked_at'])->not->toBeNull()
        ->and($lockedItem['is_unlocked'])->toBeFalse()
        ->and($lockedItem['unlocked_at'])->toBeNull();
});

test('authenticated user can view an achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();

    actingAs($user)
        ->getJson(route('api.v1.achievements.show', $achievement))
        ->assertOk()
        ->assertJsonFragment(['id' => $achievement->id]);
});

test('authenticated user cannot create achievement', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.achievements.store'), [
            'name' => 'New Achievement',
            'slug' => 'new-achievement',
            'type' => 'strength',
            'category' => 'general',
            'threshold' => 100,
        ])
        ->assertForbidden();
});

test('authenticated user cannot update achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();

    actingAs($user)
        ->putJson(route('api.v1.achievements.update', $achievement), [
            'name' => 'Updated Name',
        ])
        ->assertForbidden();
});

test('authenticated user cannot delete achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();

    actingAs($user)
        ->deleteJson(route('api.v1.achievements.destroy', $achievement))
        ->assertForbidden();
});
