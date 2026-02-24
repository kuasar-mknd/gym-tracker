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
            now()->addSeconds(30),
            fn () => $user->unreadNotifications()->count()
        );
    }

    /**
     * Get the latest achievement notification for a user, cached for performance.
     */
    public function getLatestAchievement(User $user): ?Notification
    {
        return Cache::remember(
            $this->getLatestAchievementCacheKey($user),
            now()->addSeconds(30),
            fn () => $user->unreadNotifications()
                ->where('type', \App\Notifications\AchievementUnlocked::class)
                ->latest()
                ->first()
        );
    }

    /**
     * Clear the notification-related cache for a user.
     */
    public function clearCache(User $user): void
    {
        Cache::forget($this->getUnreadCountCacheKey($user));
        Cache::forget($this->getLatestAchievementCacheKey($user));
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
