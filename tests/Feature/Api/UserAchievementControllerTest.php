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

/**
 * A freshly created account, signed in for the request under test.
 *
 * Handed back rather than parked on $this by beforeEach: Pest binds the test
 * closure at run time, so a fixture held on the test case reaches the body
 * untyped and every read of it analyses as a possible null dereference.
 */
function signInAsAchievementOwner(): User
{
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    return $user;
}

describe('UserAchievement API', function (): void {
    test('can list user achievements', function (): void {
        $user = signInAsAchievementOwner();

        $achievement = Achievement::factory()->create();
        UserAchievement::create([
            'user_id' => $user->id,
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

    test('cannot store user achievement via API', function (): void {
        $user = signInAsAchievementOwner();

        $achievement = Achievement::factory()->create();

        $response = postJson(route('api.v1.user-achievements.store'), [
            'achievement_id' => $achievement->id,
            'achieved_at' => now()->toIso8601String(),
        ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('user_achievements', [
            'user_id' => $user->id,
            'achievement_id' => $achievement->id,
        ]);
    });

    test('can show user achievement', function (): void {
        $user = signInAsAchievementOwner();

        $achievement = Achievement::factory()->create();
        $userAchievement = UserAchievement::create([
            'user_id' => $user->id,
            'achievement_id' => $achievement->id,
            'achieved_at' => now(),
        ]);

        $response = getJson(route('api.v1.user-achievements.show', $userAchievement));

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $userAchievement->id,
                'user_id' => $user->id,
            ]);
    });

    test('cannot update user achievement via API', function (): void {
        $user = signInAsAchievementOwner();

        $achievement = Achievement::factory()->create();
        $userAchievement = UserAchievement::create([
            'user_id' => $user->id,
            'achievement_id' => $achievement->id,
            'achieved_at' => now(),
        ]);

        $newDate = now()->addDay()->toIso8601String();

        $response = putJson(route('api.v1.user-achievements.update', $userAchievement), [
            'achieved_at' => $newDate,
        ]);

        $response->assertForbidden();
    });

    test('cannot delete user achievement via API', function (): void {
        $user = signInAsAchievementOwner();

        $achievement = Achievement::factory()->create();
        $userAchievement = UserAchievement::create([
            'user_id' => $user->id,
            'achievement_id' => $achievement->id,
            'achieved_at' => now(),
        ]);

        $response = deleteJson(route('api.v1.user-achievements.destroy', $userAchievement));

        $response->assertForbidden();
        $this->assertDatabaseHas('user_achievements', [
            'id' => $userAchievement->id,
        ]);
    });

    test('cannot view other users achievements', function (): void {
        signInAsAchievementOwner();

        $otherUser = User::factory()->create();
        $achievement = Achievement::factory()->create();
        $userAchievement = UserAchievement::create([
            'user_id' => $otherUser->id,
            'achievement_id' => $achievement->id,
            'achieved_at' => now(),
        ]);

        // Trying to view specific achievement of other user
        $response = getJson(route('api.v1.user-achievements.show', $userAchievement));

        $response->assertNotFound();
    });

    test('index only returns own achievements', function (): void {
        $user = signInAsAchievementOwner();

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
            'user_id' => $user->id,
            'achievement_id' => $achievement->id,
            'achieved_at' => now(),
        ]);

        $response = getJson(route('api.v1.user-achievements.index'));

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });
});
