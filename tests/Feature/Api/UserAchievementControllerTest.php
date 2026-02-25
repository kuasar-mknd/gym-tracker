<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use App\Models\UserAchievement;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('UserAchievement API', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    test('can list user achievements', function (): void {
        $achievement = Achievement::factory()->create();
        UserAchievement::create([
            'user_id' => $this->user->id,
            'achievement_id' => $achievement->id,
            'achieved_at' => now(),
        ]);

        $response = getJson(route('api.v1.user-achievements.index', ['include' => 'achievement']));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'user_id',
                        'achievement_id',
                        'achieved_at',
                        'achievement',
                    ],
                ],
            ]);
    });

    // Removed 'can store user achievement' as it is now forbidden

    test('can show user achievement', function (): void {
        $achievement = Achievement::factory()->create();
        $userAchievement = UserAchievement::create([
            'user_id' => $this->user->id,
            'achievement_id' => $achievement->id,
            'achieved_at' => now(),
        ]);

        $response = getJson(route('api.v1.user-achievements.show', $userAchievement));

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $userAchievement->id,
                'user_id' => $this->user->id,
            ]);
    });

    // Removed 'can update user achievement' as it is now forbidden

    // Removed 'can delete user achievement' as it is now forbidden

    test('cannot view other users achievements', function (): void {
        $otherUser = User::factory()->create();
        $achievement = Achievement::factory()->create();
        $userAchievement = UserAchievement::create([
            'user_id' => $otherUser->id,
            'achievement_id' => $achievement->id,
            'achieved_at' => now(),
        ]);

        // Trying to view specific achievement of other user
        $response = getJson(route('api.v1.user-achievements.show', $userAchievement));

        $response->assertForbidden();
    });

    test('index only returns own achievements', function (): void {
        $otherUser = User::factory()->create();
        $achievement = Achievement::factory()->create();

        // Create for other user
        UserAchievement::create([
            'user_id' => $otherUser->id,
            'achievement_id' => $achievement->id,
            'achieved_at' => now(),
        ]);

        // Create for current user
        UserAchievement::create([
            'user_id' => $this->user->id,
            'achievement_id' => $achievement->id,
            'achieved_at' => now(),
        ]);

        $response = getJson(route('api.v1.user-achievements.index'));

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });
});
