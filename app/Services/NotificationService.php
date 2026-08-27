<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification as Notification;
use Illuminate\Support\Facades\Cache;

/**
 * Service for managing user notifications and their cached states.
 */
final class NotificationService
{
    /**
     * Get the unread notifications count for a user, cached for performance.
     */
    public function getUnreadCount(User $user): int
    {
        return (int) Cache::remember(
            $this->getUnreadCountCacheKey($user),
            now()->addDays(7),
            fn () => $user->unreadNotifications()->count()
        );
    }

    /**
     * Get the latest achievement notification for a user, cached for performance.
     */
    /**
     * Le dernier trophee non lu, ou rien.
     *
     * L'absence est enveloppee : `Cache::remember()` ne sert le cache que si la
     * valeur n'est pas nulle, si bien qu'un `null` stocke est indiscernable
     * d'une absence. La requete repartait donc a chaque requete web pour tout
     * compte a jour — c'est-a-dire dans le cas normal.
     */
    public function getLatestAchievement(User $user): ?Notification
    {
        $enveloppe = Cache::remember(
            $this->getLatestAchievementCacheKey($user),
            now()->addDays(7),
            fn (): array => ['trophee' => self::chercherTrophee($user)],
        );

        if (! is_array($enveloppe)) {
            return null;
        }

        $trophee = $enveloppe['trophee'] ?? null;

        return $trophee instanceof Notification ? $trophee : null;
    }

    /**
     * Clear the notification-related cache for a user.
     */
    private static function chercherTrophee(User $user): ?Notification
    {
        return $user->unreadNotifications()
            ->where('type', \App\Notifications\AchievementUnlocked::class)
            ->latest()
            ->first();
    }

    /**
     * @param  class-string|null  $notification  Le type envoye, s'il est connu.
     */
    public function clearCache(User $user, ?string $notification = null): void
    {
        Cache::forget($this->getUnreadCountCacheKey($user));

        /*
         * Le trophee n'est oublie que si c'en etait un. Vider les deux cles a
         * chaque notification faisait retomber le cache du trophee sur des
         * envois qui ne le concernent pas — et un record en envoie trois par
         * serie qui le bat.
         */
        if ($notification === null || $notification === \App\Notifications\AchievementUnlocked::class) {
            Cache::forget($this->getLatestAchievementCacheKey($user));
        }
    }

    private function getUnreadCountCacheKey(User $user): string
    {
        return "user:{$user->id}:unread_notifications_count";
    }

    private function getLatestAchievementCacheKey(User $user): string
    {
        return "user:{$user->id}:latest_achievement";
    }
}
