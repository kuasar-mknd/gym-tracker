<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('user can view notifications page', function (): void {
    $user = User::factory()->create();

    // Create a notification for the user using relationship
    $notification = $user->notifications()->create([
        'id' => Str::uuid()->toString(),
        'type' => 'App\Notifications\PersonalRecordAchieved',
        'data' => ['message' => 'New PR!', 'action_url' => '/'],
        'read_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('notifications.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Notifications/Index')
        ->has('notifications.data', 1)
        ->where('notifications.data.0.id', $notification->id)
    );
});

test('guest cannot view notifications page', function (): void {
    $response = $this->get(route('notifications.index'));

    $response->assertRedirect(route('login'));
});

test('user can mark a notification as read', function (): void {
    $user = User::factory()->create();

    $notification = $user->notifications()->create([
        'id' => Str::uuid()->toString(),
        'type' => 'App\Notifications\PersonalRecordAchieved',
        'data' => ['message' => 'New PR!'],
        'read_at' => null,
    ]);

    $response = $this->actingAs($user)->post(route('notifications.mark-as-read', $notification->id));

    $response->assertRedirect();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('user cannot mark another users notification as read', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $notification = $otherUser->notifications()->create([
        'id' => Str::uuid()->toString(),
        'type' => 'App\Notifications\PersonalRecordAchieved',
        'data' => ['message' => 'New PR!'],
        'read_at' => null,
    ]);

    $response = $this->actingAs($user)->post(route('notifications.mark-as-read', $notification->id));

    $response->assertRedirect();

    expect($notification->fresh()->read_at)->toBeNull();
});

test('user can mark all notifications as read', function (): void {
    $user = User::factory()->create();

    $user->notifications()->create([
        'id' => Str::uuid()->toString(),
        'type' => 'App\Notifications\PersonalRecordAchieved',
        'data' => ['message' => '1'],
        'read_at' => null,
    ]);

    $user->notifications()->create([
        'id' => Str::uuid()->toString(),
        'type' => 'App\Notifications\PersonalRecordAchieved',
        'data' => ['message' => '2'],
        'read_at' => null,
    ]);

    $response = $this->actingAs($user)->post(route('notifications.mark-all-as-read'));

    $response->assertRedirect();

    $unreadCount = $user->unreadNotifications()->count();

    expect($unreadCount)->toBe(0);
});

test('marking non-existent notification as read does nothing', function (): void {
    $user = User::factory()->create();
    $nonExistentId = Str::uuid()->toString();

    $response = $this->actingAs($user)->post(route('notifications.mark-as-read', $nonExistentId));

    $response->assertRedirect();
    // No assertion on DB as nothing should happen, effectively testing "Happy Path" of invalid ID
});
