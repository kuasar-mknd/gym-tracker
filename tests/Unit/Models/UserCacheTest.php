<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class UserCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_unread_notifications_count_cached_interacts_with_cache(): void
    {
        $user = User::factory()->create();
        $cacheKey = "user:{$user->id}:unread_notifications_count";

        // Expect Cache::remember to be called with correct key and TTL
        Cache::shouldReceive('remember')
            ->once()
            ->with(
                $cacheKey,
                Mockery::on(fn($ttl): bool => $ttl->diffInSeconds(now()) <= 30),
                Mockery::type('Closure')
            )
            ->andReturn(5);

        $count = $user->getUnreadNotificationsCountCached();

        $this->assertEquals(5, $count);
    }

    public function test_get_unread_notifications_count_cached_closure_counts_unread_notifications(): void
    {
        $user = User::factory()->create();

        // Create 2 unread and 1 read notifications
        $user->notifications()->create([
            'id' => Str::uuid(),
            'type' => 'TestNotification',
            'data' => [],
            'read_at' => null,
        ]);
        $user->notifications()->create([
            'id' => Str::uuid(),
            'type' => 'TestNotification',
            'data' => [],
            'read_at' => null,
        ]);
        $user->notifications()->create([
            'id' => Str::uuid(),
            'type' => 'TestNotification',
            'data' => [],
            'read_at' => now(),
        ]);

        // We use Cache::spy() to allow the real implementation to run while still verifying interaction
        Cache::spy();

        $count = $user->getUnreadNotificationsCountCached();

        $this->assertEquals(2, $count);
        Cache::shouldHaveReceived('remember')
            ->once()
            ->with(
                "user:{$user->id}:unread_notifications_count",
                Mockery::any(),
                Mockery::type('Closure')
            );
    }

    public function test_get_latest_achievement_cached_interacts_with_cache(): void
    {
        $user = User::factory()->create();
        $cacheKey = "user:{$user->id}:latest_achievement";

        Cache::shouldReceive('remember')
            ->once()
            ->with(
                $cacheKey,
                Mockery::on(fn($ttl): bool => $ttl->diffInSeconds(now()) <= 30),
                Mockery::type('Closure')
            )
            ->andReturn(null);

        $achievement = $user->getLatestAchievementCached();

        $this->assertNull($achievement);
    }

    public function test_get_latest_achievement_cached_closure_filters_achievements(): void
    {
        $user = User::factory()->create();

        // Create a regular notification
        $user->notifications()->create([
            'id' => Str::uuid(),
            'type' => 'TestNotification',
            'data' => [],
            'read_at' => null,
        ]);

        // Create an achievement notification
        $achievement = $user->notifications()->create([
            'id' => Str::uuid(),
            'type' => \App\Notifications\AchievementUnlocked::class,
            'data' => ['achievement_id' => 1],
            'read_at' => null,
        ]);

        Cache::spy();

        $result = $user->getLatestAchievementCached();

        $this->assertNotNull($result);
        $this->assertEquals($achievement->id, $result->id);

        Cache::shouldHaveReceived('remember')
            ->once()
            ->with(
                "user:{$user->id}:latest_achievement",
                Mockery::any(),
                Mockery::type('Closure')
            );
    }
}
