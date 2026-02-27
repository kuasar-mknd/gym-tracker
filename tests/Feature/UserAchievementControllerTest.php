<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\User;
use App\Models\UserAchievement;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\getJson;

test('authenticated user can list their achievements', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    $userAchievement = UserAchievement::create([
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
        'achieved_at' => now(),
    ]);

    actingAs($user)
        ->getJson(route('api.v1.user-achievements.index', ['include' => 'achievement']))
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'user_id',
                    'achievement_id',
                    'achieved_at',
                    'achievement' => [
                        'id',
                        'name',
                        'slug',
                    ],
                ],
            ],
            'links',
            'meta',
        ])
        ->assertJsonFragment(['id' => $userAchievement->id]);
});

test('unauthenticated user cannot list achievements', function (): void {
    getJson(route('api.v1.user-achievements.index'))
        ->assertUnauthorized();
});

test('authenticated user can store a user achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.user-achievements.store'), [
            'achievement_id' => $achievement->id,
            'achieved_at' => now()->toDateTimeString(),
        ])
        ->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'id',
                'user_id',
                'achievement_id',
                'achieved_at',
            ],
        ]);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

test('store validation fails for missing achievement_id', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.user-achievements.store'), [
            'achieved_at' => now()->toDateTimeString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['achievement_id']);
});

test('store validation fails for invalid achievement_id', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.user-achievements.store'), [
            'achievement_id' => 999999,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['achievement_id']);
});

test('authenticated user can view a specific user achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    $userAchievement = UserAchievement::create([
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
        'achieved_at' => now(),
    ]);

    actingAs($user)
        ->getJson(route('api.v1.user-achievements.show', $userAchievement))
        ->assertOk()
        ->assertJson([
            'data' => [
                'id' => $userAchievement->id,
                'user_id' => $user->id,
                'achievement_id' => $achievement->id,
            ],
        ]);
});

test('user cannot view another users achievement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $achievement = Achievement::factory()->create();
    $userAchievement = UserAchievement::create([
        'user_id' => $otherUser->id,
        'achievement_id' => $achievement->id,
        'achieved_at' => now(),
    ]);

    actingAs($user)
        ->getJson(route('api.v1.user-achievements.show', $userAchievement))
        ->assertForbidden();
});

test('authenticated user can update their user achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    $userAchievement = UserAchievement::create([
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
        'achieved_at' => now()->subDay(),
    ]);

    $newDate = now()->toDateTimeString();

    actingAs($user)
        ->patchJson(route('api.v1.user-achievements.update', $userAchievement), [
            'achieved_at' => $newDate,
        ])
        ->assertOk();

    $userAchievement->refresh();

    // Use loose comparison or format check because database precision might vary
    expect($userAchievement->achieved_at->toDateTimeString())->toBe($newDate);
});

test('user cannot update another users achievement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $achievement = Achievement::factory()->create();
    $userAchievement = UserAchievement::create([
        'user_id' => $otherUser->id,
        'achievement_id' => $achievement->id,
        'achieved_at' => now(),
    ]);

    actingAs($user)
        ->patchJson(route('api.v1.user-achievements.update', $userAchievement), [
            'achieved_at' => now()->toDateTimeString(),
        ])
        ->assertForbidden();
});

test('authenticated user can delete their user achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    $userAchievement = UserAchievement::create([
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
        'achieved_at' => now(),
    ]);

    actingAs($user)
        ->deleteJson(route('api.v1.user-achievements.destroy', $userAchievement))
        ->assertNoContent();

    assertDatabaseMissing('user_achievements', ['id' => $userAchievement->id]);
});

test('user cannot delete another users achievement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $achievement = Achievement::factory()->create();
    $userAchievement = UserAchievement::create([
        'user_id' => $otherUser->id,
        'achievement_id' => $achievement->id,
        'achieved_at' => now(),
    ]);

    actingAs($user)
        ->deleteJson(route('api.v1.user-achievements.destroy', $userAchievement))
        ->assertForbidden();

    assertDatabaseHas('user_achievements', ['id' => $userAchievement->id]);
});
