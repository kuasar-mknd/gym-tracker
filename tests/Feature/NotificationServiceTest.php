<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\User;
use App\Notifications\AchievementUnlocked;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService();
    }

    public function test_get_unread_count_cached(): void
    {
        $user = User::factory()->create();

        $user->notify(new AchievementUnlocked(Achievement::factory()->create()));
        $user->notify(new AchievementUnlocked(Achievement::factory()->create()));

        $this->assertEquals(2, $this->service->getUnreadCount($user));

        // Ensure it's cached
        $user->unreadNotifications()->update(['read_at' => now()]);
        $this->assertEquals(2, $this->service->getUnreadCount($user));

        $this->service->clearCache($user);
        $this->assertEquals(0, $this->service->getUnreadCount($user));
    }

    public function test_get_latest_achievement_cached(): void
    {
        $user = User::factory()->create();
        $achievement = Achievement::factory()->create(['name' => 'First']);
        $user->notify(new AchievementUnlocked($achievement));

        $latest = $this->service->getLatestAchievement($user);
        $this->assertNotNull($latest);
        $this->assertEquals($achievement->id, $latest->data['achievement_id']);

        // Ensure it's cached
        $user->unreadNotifications()->update(['read_at' => now()]);
        $this->assertNotNull($this->service->getLatestAchievement($user));

        $this->service->clearCache($user);
        $this->assertNull($this->service->getLatestAchievement($user));
    }

    public function test_middleware_uses_notification_service(): void
    {
        $user = User::factory()->create();
        $user->notify(new AchievementUnlocked(Achievement::factory()->create()));

        $this->actingAs($user);

        // We can check the shared data by calling a route and checking Inertia props
        // or just by instantiating the middleware and calling share()

        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(fn () => $user);

        $middleware = new \App\Http\Middleware\HandleInertiaRequests();
        $sharedData = $middleware->share($request);

        $this->assertEquals(1, $sharedData['auth']['user']['unread_notifications_count']);
        $this->assertNotNull($sharedData['auth']['user']['latest_achievement']);
    }

    public function test_controller_clears_cache_when_marking_as_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new AchievementUnlocked(Achievement::factory()->create()));

        $this->actingAs($user);

        // Prime the cache
        $this->assertEquals(1, $this->service->getUnreadCount($user));

        // Mark as read via controller
        $notification = $user->unreadNotifications()->first();
        $response = $this->post(route('notifications.mark-as-read', $notification->id));

        $response->assertRedirect();

        // Cache should be cleared and now return 0
        $this->assertEquals(0, $this->service->getUnreadCount($user));
    }

    public function test_controller_clears_cache_when_marking_all_as_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new AchievementUnlocked(Achievement::factory()->create()));

        $this->actingAs($user);

        // Prime the cache
        $this->assertEquals(1, $this->service->getUnreadCount($user));

        // Mark all as read via controller
        $response = $this->post(route('notifications.mark-all-as-read'));

        $response->assertRedirect();

        // Cache should be cleared and now return 0
        $this->assertEquals(0, $this->service->getUnreadCount($user));
    }
}
