<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use App\Models\UserAchievement;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('UserAchievement Security', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    test('users cannot self-award achievements', function (): void {
        $achievement = Achievement::factory()->create();

        // This should fail with 403 Forbidden, but currently passes (201 Created)
        $response = postJson(route('api.v1.user-achievements.store'), [
            'achievement_id' => $achievement->id,
            'achieved_at' => now()->toIso8601String(),
        ]);

        $response->assertForbidden();
    });

    test('users cannot update their achievements', function (): void {
        $achievement = Achievement::factory()->create();
        $userAchievement = UserAchievement::create([
            'user_id' => $this->user->id,
            'achievement_id' => $achievement->id,
            'achieved_at' => now(),
        ]);

        $response = putJson(route('api.v1.user-achievements.update', $userAchievement), [
            'achieved_at' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertForbidden();
    });

    test('users cannot delete their achievements', function (): void {
        $achievement = Achievement::factory()->create();
        $userAchievement = UserAchievement::create([
            'user_id' => $this->user->id,
            'achievement_id' => $achievement->id,
            'achieved_at' => now(),
        ]);

        $response = deleteJson(route('api.v1.user-achievements.destroy', $userAchievement));

        $response->assertForbidden();
    });
});
