<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_push_enabled_returns_true_when_enabled(): void
    {
        $user = User::factory()->create();

        $user->notificationPreferences()->create([
            'type' => 'workout_reminder',
            'is_enabled' => true,
            'is_push_enabled' => true,
        ]);

        $this->assertTrue($user->isPushEnabled('workout_reminder'));
    }

    public function test_is_push_enabled_returns_false_when_disabled(): void
    {
        $user = User::factory()->create();

        $user->notificationPreferences()->create([
            'type' => 'workout_reminder',
            'is_enabled' => true,
            'is_push_enabled' => false,
        ]);

        $this->assertFalse($user->isPushEnabled('workout_reminder'));
    }

    public function test_is_push_enabled_returns_false_when_not_found(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isPushEnabled('workout_reminder'));
    }

    public function test_is_push_enabled_uses_loaded_relation_if_available(): void
    {
        $user = User::factory()->create();

        $user->notificationPreferences()->create([
            'type' => 'workout_reminder',
            'is_enabled' => true,
            'is_push_enabled' => true,
        ]);

        $user->load('notificationPreferences');

        // This will not make a database query because relation is loaded
        $this->assertTrue($user->isPushEnabled('workout_reminder'));
    }
}
