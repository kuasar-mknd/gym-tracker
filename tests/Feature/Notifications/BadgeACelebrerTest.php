<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use App\Notifications\AchievementUnlocked;
use Inertia\Testing\AssertableInertia;

/*
 * Le badge que la modale « Badge débloqué » célèbre, tel que chaque page le
 * partage dans `auth.user.latest_achievement` (#2052).
 *
 * La notification ne range que son message et l'identifiant du badge : la
 * modale lisait pourtant l'icône et le nom dans la notification, et célébrait
 * un carré vide, sans nom. Le serveur les joint désormais, avec la description,
 * depuis le badge lui-même.
 */

it('joint au dernier badge non lu son icône, son nom et sa description', function (): void {
    $user = User::factory()->create();
    $badge = Achievement::factory()->create([
        'name' => 'Guerrier de la Semaine',
        'icon' => '⚔️',
        'description' => "Tu as complété 3 séances d'entraînement.",
    ]);
    $user->notify(new AchievementUnlocked($badge));
    $notification = $user->unreadNotifications()->sole();

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.user.latest_achievement.id', $notification->id)
            ->where('auth.user.latest_achievement.name', 'Guerrier de la Semaine')
            ->where('auth.user.latest_achievement.icon', '⚔️')
            ->where('auth.user.latest_achievement.description', "Tu as complété 3 séances d'entraînement.")
            ->where('auth.user.latest_achievement.data.message', 'Félicitations ! Tu as débloqué le badge : Guerrier de la Semaine.')
            ->etc()
        );
});

it('laisse vides l’icône, le nom et la description d’un badge supprimé depuis, et garde le message', function (): void {
    $user = User::factory()->create();
    $badge = Achievement::factory()->create(['name' => 'Novice du Volume']);
    $user->notify(new AchievementUnlocked($badge));
    $badge->delete();

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.user.latest_achievement.name', null)
            ->where('auth.user.latest_achievement.icon', null)
            ->where('auth.user.latest_achievement.description', null)
            ->where('auth.user.latest_achievement.data.message', 'Félicitations ! Tu as débloqué le badge : Novice du Volume.')
            ->etc()
        );
});
