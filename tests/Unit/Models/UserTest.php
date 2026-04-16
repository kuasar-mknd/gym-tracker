<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Mockery;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_latest_achievement_cached_delegates_to_notification_service(): void
    {
        $user = User::factory()->create();

        $mockNotification = new DatabaseNotification(['id' => 'test-id']);

        // Use Mockery::mock but without type hinting the class since it's final
        $mockService = Mockery::mock();
        $mockService->shouldReceive('getLatestAchievement')
            ->once()
            ->with($user)
            ->andReturn($mockNotification);

        $this->app->instance(NotificationService::class, $mockService);

        $result = $user->getLatestAchievementCached();

        $this->assertSame($mockNotification, $result);
    }

    public function test_get_unread_notifications_count_cached_delegates_to_notification_service(): void
    {
        $user = User::factory()->create();

        $mockService = Mockery::mock();
        $mockService->shouldReceive('getUnreadCount')
            ->once()
            ->with($user)
            ->andReturn(5);

        $this->app->instance(NotificationService::class, $mockService);

        $result = $user->getUnreadNotificationsCountCached();

        $this->assertEquals(5, $result);
    }
}
