<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_notification_enabled_returns_true_if_enabled(): void
    {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'test_type',
            'is_enabled' => true,
        ]);

        $this->assertTrue($user->isNotificationEnabled('test_type'));
    }

    public function test_is_notification_enabled_returns_false_if_disabled(): void
    {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'test_type',
            'is_enabled' => false,
        ]);

        $this->assertFalse($user->isNotificationEnabled('test_type'));
    }

    public function test_is_notification_enabled_returns_false_if_not_exists(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isNotificationEnabled('test_type'));
    }

    public function test_is_notification_enabled_is_type_specific(): void
    {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'other_type',
            'is_enabled' => true,
        ]);

        $this->assertFalse($user->isNotificationEnabled('test_type'));
    }

    public function test_is_push_enabled_returns_true_if_push_enabled(): void
    {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'test_type',
            'is_push_enabled' => true,
        ]);

        $this->assertTrue($user->isPushEnabled('test_type'));
    }

    public function test_is_push_enabled_returns_false_if_push_disabled(): void
    {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'test_type',
            'is_push_enabled' => false,
        ]);

        $this->assertFalse($user->isPushEnabled('test_type'));
    }

    public function test_is_push_enabled_returns_false_if_not_exists(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isPushEnabled('test_type'));
    }
}
