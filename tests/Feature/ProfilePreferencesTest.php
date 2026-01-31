<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\patch;

uses(RefreshDatabase::class);

test('authenticated user can update notification preferences', function (): void {
    $user = User::factory()->create();

    $preferences = [
        'daily_reminder' => true,
        'weekly_summary' => false,
        'achievement_unlocked' => true,
    ];

    $pushPreferences = [
        'daily_reminder' => true,
        'weekly_summary' => false,
        'achievement_unlocked' => false,
    ];

    $values = [
        'daily_reminder' => 10,
    ];

    $response = actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => $preferences,
            'push_preferences' => $pushPreferences,
            'values' => $values,
        ]);

    $response
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'notification-preferences-updated');

    assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'type' => 'daily_reminder',
        'is_enabled' => true,
        'is_push_enabled' => true,
        'value' => 10,
    ]);

    assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'type' => 'weekly_summary',
        'is_enabled' => false,
        'is_push_enabled' => false,
        'value' => null,
    ]);

    assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'type' => 'achievement_unlocked',
        'is_enabled' => true,
        'is_push_enabled' => false,
        'value' => null,
    ]);
});

test('preferences are upserted correctly', function (): void {
    $user = User::factory()->create();

    // Create existing preference
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => 'daily_reminder',
        'is_enabled' => false,
        'is_push_enabled' => false,
        'value' => 5,
    ]);

    $preferences = [
        'daily_reminder' => true,
    ];

    $pushPreferences = [
        'daily_reminder' => true,
    ];

    $values = [
        'daily_reminder' => 20,
    ];

    $response = actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => $preferences,
            'push_preferences' => $pushPreferences,
            'values' => $values,
        ]);

    $response->assertRedirect(route('profile.edit'));

    // Should have updated existing
    expect(NotificationPreference::where('user_id', $user->id)->count())->toBe(1);

    assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'type' => 'daily_reminder',
        'is_enabled' => true,
        'is_push_enabled' => true,
        'value' => 20,
    ]);
});

test('validation: required fields', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->patch(route('profile.preferences.update'), [])
        ->assertSessionHasErrors(['preferences', 'push_preferences']);
});

test('validation: invalid preference types', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => [
                'invalid_type' => true,
            ],
            'push_preferences' => [],
        ])
        ->assertSessionHasErrors(['preferences']);
});

test('validation: values must be integers', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => ['daily_reminder' => true],
            'push_preferences' => ['daily_reminder' => true],
            'values' => ['daily_reminder' => 'not-an-integer'],
        ])
        ->assertSessionHasErrors(['values.daily_reminder']);
});

test('validation: values range', function (): void {
    $user = User::factory()->create();

    // Min 1
    actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => ['daily_reminder' => true],
            'push_preferences' => ['daily_reminder' => true],
            'values' => ['daily_reminder' => 0],
        ])
        ->assertSessionHasErrors(['values.daily_reminder']);

    // Max 30
    actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => ['daily_reminder' => true],
            'push_preferences' => ['daily_reminder' => true],
            'values' => ['daily_reminder' => 31],
        ])
        ->assertSessionHasErrors(['values.daily_reminder']);
});

test('unauthenticated user cannot update preferences', function (): void {
    patch(route('profile.preferences.update'), [
        'preferences' => ['daily_reminder' => true],
    ])->assertRedirect(route('login'));
});
