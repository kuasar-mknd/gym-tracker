<?php

namespace Tests\Feature\Api\V1;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_notification_preferences(): void
    {
        $user = User::factory()->create();
        $preferences = NotificationPreference::factory()->count(3)->create([
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notification-preferences.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'type',
                        'is_enabled',
                        'is_push_enabled',
                        'value',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_user_can_create_notification_preference(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $data = [
            'type' => 'daily_reminder',
            'is_enabled' => true,
            'is_push_enabled' => false,
            'value' => 10,
        ];

        $response = $this->postJson(route('api.v1.notification-preferences.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment($data);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'type' => 'daily_reminder',
            'is_enabled' => true,
            'is_push_enabled' => false,
            'value' => 10,
        ]);
    }

    public function test_user_cannot_create_duplicate_preference_type(): void
    {
        $user = User::factory()->create();
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'type' => 'daily_reminder',
        ]);

        Sanctum::actingAs($user);

        $data = [
            'type' => 'daily_reminder',
            'is_enabled' => true,
            'is_push_enabled' => false,
        ];

        $response = $this->postJson(route('api.v1.notification-preferences.store'), $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_user_can_show_preference(): void
    {
        $user = User::factory()->create();
        $preference = NotificationPreference::factory()->create([
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notification-preferences.show', $preference));

        $response->assertOk()
            ->assertJsonFragment(['id' => $preference->id]);
    }

    public function test_user_cannot_show_other_users_preference(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $preference = NotificationPreference::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notification-preferences.show', $preference));

        $response->assertForbidden();
    }

    public function test_user_can_update_preference(): void
    {
        $user = User::factory()->create();
        $preference = NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'is_enabled' => false,
        ]);

        Sanctum::actingAs($user);

        $data = [
            'is_enabled' => true,
        ];

        $response = $this->putJson(route('api.v1.notification-preferences.update', $preference), $data);

        $response->assertOk()
            ->assertJsonFragment(['is_enabled' => true]);

        $this->assertDatabaseHas('notification_preferences', [
            'id' => $preference->id,
            'is_enabled' => true,
        ]);
    }

    public function test_user_cannot_update_other_users_preference(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $preference = NotificationPreference::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson(route('api.v1.notification-preferences.update', $preference), ['is_enabled' => true]);

        $response->assertForbidden();
    }

    public function test_user_can_delete_preference(): void
    {
        $user = User::factory()->create();
        $preference = NotificationPreference::factory()->create([
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.notification-preferences.destroy', $preference));

        $response->assertNoContent();

        $this->assertDatabaseMissing('notification_preferences', ['id' => $preference->id]);
    }

    public function test_user_cannot_delete_other_users_preference(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $preference = NotificationPreference::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.v1.notification-preferences.destroy', $preference));

        $response->assertForbidden();
    }
}
