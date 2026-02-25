<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class NotificationServiceCacheTest extends TestCase
{
    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService();
    }

    public function test_get_unread_count_interacts_with_cache(): void
    {
        $user = User::factory()->make(['id' => 123]);
        $cacheKey = "user:{$user->id}:unread_notifications_count";

        Cache::shouldReceive('remember')
            ->once()
            ->with(
                $cacheKey,
                Mockery::on(fn ($ttl): bool => $ttl->diffInSeconds(now()) <= 30),
                Mockery::type('Closure')
            )
            ->andReturn(5);

        $count = $this->service->getUnreadCount($user);

        $this->assertEquals(5, $count);
    }

    public function test_get_latest_achievement_interacts_with_cache(): void
    {
        $user = User::factory()->make(['id' => 123]);
        $cacheKey = "user:{$user->id}:latest_achievement";

        Cache::shouldReceive('remember')
            ->once()
            ->with(
                $cacheKey,
                Mockery::on(fn ($ttl): bool => $ttl->diffInSeconds(now()) <= 30),
                Mockery::type('Closure')
            )
            ->andReturn(null);

        $achievement = $this->service->getLatestAchievement($user);

        $this->assertNull($achievement);
    }

    public function test_clear_cache_clears_correct_keys(): void
    {
        $user = User::factory()->make(['id' => 123]);

        Cache::shouldReceive('forget')->once()->with("user:{$user->id}:unread_notifications_count");
        Cache::shouldReceive('forget')->once()->with("user:{$user->id}:latest_achievement");

        $this->service->clearCache($user);
    }
}
