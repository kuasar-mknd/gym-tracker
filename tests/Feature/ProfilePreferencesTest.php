<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\patch;

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

    $days = [
        'daily_reminder' => [1, 3],
    ];

    $response = actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => $preferences,
            'push_preferences' => $pushPreferences,
            'days' => $days,
        ]);

    $response
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'notification-preferences-updated');

    assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'type' => 'daily_reminder',
        'is_enabled' => true,
        'is_push_enabled' => true,
    ]);
    expect(NotificationPreference::where('user_id', $user->id)->where('type', 'daily_reminder')->sole()->days)->toBe([1, 3]);

    assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'type' => 'weekly_summary',
        'is_enabled' => false,
        'is_push_enabled' => false,
        'days' => null,
    ]);

    assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'type' => 'achievement_unlocked',
        'is_enabled' => true,
        'is_push_enabled' => false,
        'days' => null,
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

    $days = [
        'daily_reminder' => [7, 6],
    ];

    $response = actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => $preferences,
            'push_preferences' => $pushPreferences,
            'days' => $days,
        ]);

    $response->assertRedirect(route('profile.edit'));

    // Should have updated existing
    expect(NotificationPreference::where('user_id', $user->id)->count())->toBe(1);

    assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'type' => 'daily_reminder',
        'is_enabled' => true,
        'is_push_enabled' => true,
    ]);
    expect(NotificationPreference::where('user_id', $user->id)->where('type', 'daily_reminder')->sole()->days)->toBe([6, 7]);
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

test('validation: days must be ISO weekday numbers', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => ['daily_reminder' => true],
            'push_preferences' => ['daily_reminder' => true],
            'days' => ['daily_reminder' => ['lundi']],
        ])
        ->assertSessionHasErrors(['days.daily_reminder.0']);

    actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => ['daily_reminder' => true],
            'push_preferences' => ['daily_reminder' => true],
            'days' => ['daily_reminder' => [0, 8]],
        ])
        ->assertSessionHasErrors(['days.daily_reminder.0', 'days.daily_reminder.1']);
});

test('validation: days, when sent, hold at least one day and no duplicate', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => ['daily_reminder' => true],
            'push_preferences' => ['daily_reminder' => true],
            'days' => ['daily_reminder' => []],
        ])
        ->assertSessionHasErrors(['days.daily_reminder']);

    actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => ['daily_reminder' => true],
            'push_preferences' => ['daily_reminder' => true],
            'days' => ['daily_reminder' => [2, 2]],
        ])
        ->assertSessionHasErrors(['days.daily_reminder.0']);

    // Absents, les jours valent « tous les jours » : pas d'erreur.
    actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => ['daily_reminder' => true],
            'push_preferences' => ['daily_reminder' => true],
        ])
        ->assertSessionHasNoErrors();
});

test('unauthenticated user cannot update preferences', function (): void {
    patch(route('profile.preferences.update'), [
        'preferences' => ['daily_reminder' => true],
    ])->assertRedirect(route('login'));
});

/*
 * Les clés acceptées par la requête étaient contraintes par une fermeture
 * maison dont le chemin d'échec n'était couvert par rien : ni test, ni
 * traduction, ni interface ne lisait son message. Elle est remplacée par
 * `Rule::array()`, mais la règle qu'elle portait, elle, mérite d'être tenue.
 */
test('rejette une clé de préférence que le domaine ne connaît pas', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => ['daily_reminder' => true, 'inconnue_au_bataillon' => true],
            'push_preferences' => ['daily_reminder' => true],
        ])
        ->assertSessionHasErrors('preferences');

    // La clé inventée ne doit avoir créé aucune ligne, pas même la valide qui
    // l'accompagnait : la requête est refusée en bloc.
    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('accepte les clés que le domaine connaît', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->patch(route('profile.preferences.update'), [
            'preferences' => ['daily_reminder' => true, 'personal_record' => false],
            'push_preferences' => ['daily_reminder' => true, 'personal_record' => false],
        ])
        ->assertSessionHasNoErrors();

    assertDatabaseHas(NotificationPreference::class, [
        'user_id' => $user->id,
        'type' => 'daily_reminder',
        'is_enabled' => true,
    ]);
});

/*
 * La page enregistre les préférences en XHR (axios). Un 302 y serait suivi en
 * gardant la méthode : PATCH /profile/edit, 405, et un message d'échec pour
 * une écriture pourtant faite (vu en production le 2026-09-04).
 */
it('répond 204 à un client XHR, sans redirection à rejouer en PATCH', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->patchJson(route('profile.preferences.update'), [
            'preferences' => ['personal_record' => true, 'training_reminder' => false],
            'push_preferences' => ['personal_record' => false, 'training_reminder' => false],
        ])
        ->assertNoContent();

    assertDatabaseHas('notification_preferences', [
        'user_id' => $user->id,
        'type' => 'training_reminder',
        'is_enabled' => false,
    ]);
});
