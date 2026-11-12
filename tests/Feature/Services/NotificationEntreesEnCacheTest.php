<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use App\Notifications\AchievementUnlocked;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('rend le compte en entier même quand le cache le garde en texte', function (): void {
    $user = User::factory()->create();
    Cache::put("user:{$user->id}:unread_notifications_count", '3', 600);

    expect(app(NotificationService::class)->getUnreadCount($user))->toBe(3);
});

it('garde le compte et le trophée sept jours, pas un de plus', function (string $methode, string $cle): void {
    Carbon::setTestNow('2026-11-10 12:00:00');
    $user = User::factory()->create();
    $user->notify(new AchievementUnlocked(Achievement::factory()->create()));
    $service = app(NotificationService::class);

    $service->{$methode}($user);
    $cleComplete = "user:{$user->id}:{$cle}";

    Carbon::setTestNow('2026-11-17 00:00:00');
    expect(Cache::has($cleComplete))->toBeTrue();

    Carbon::setTestNow('2026-11-17 13:00:00');
    expect(Cache::has($cleComplete))->toBeFalse();
})->with([
    'le compte des non lues' => ['getUnreadCount', 'unread_notifications_count'],
    'le dernier trophée' => ['getLatestAchievement', 'latest_achievement'],
]);

it('ne rend un trophée que si l’enveloppe en cache en porte un', function (mixed $enveloppe): void {
    $user = User::factory()->create();
    Cache::put("user:{$user->id}:latest_achievement", $enveloppe, 600);

    expect(app(NotificationService::class)->getLatestAchievement($user))->toBeNull();
})->with([
    'un texte au lieu de l’enveloppe' => ['pas une enveloppe'],
    'une enveloppe sans trophée' => [[]],
    'une enveloppe dont le trophée n’en est pas un' => [['trophee' => 'pas un trophée']],
    'une enveloppe dont le trophée est un tableau' => [['trophee' => ['id' => 1]]],
]);

it('rend le trophée que porte l’enveloppe', function (): void {
    $user = User::factory()->create();
    $user->notify(new AchievementUnlocked(Achievement::factory()->create()));
    $notification = $user->unreadNotifications()->firstOrFail();
    Cache::put("user:{$user->id}:latest_achievement", ['trophee' => $notification], 600);

    expect(app(NotificationService::class)->getLatestAchievement($user)?->id)->toBe($notification->id);
});
