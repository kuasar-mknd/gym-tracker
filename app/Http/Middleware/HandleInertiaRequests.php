<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $this->getUserData($request),
            ],
            'is_testing' => app()->environment('testing'),
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }

    /**
     * Get the shared user data.
     *
     * @return array<string, mixed>|null
     */
    private function getUserData(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof \App\Models\User) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'unread_notifications_count' => $user->unreadNotifications()->count(),
            'latest_achievement' => $user->unreadNotifications()
                ->where('type', \App\Notifications\AchievementUnlocked::class)
                ->latest()
                ->first(),
            'current_streak' => $user->last_workout_at && $user->last_workout_at->startOfDay()->diffInDays(now()->startOfDay()) > 1 ? 0 : $user->current_streak,
            'longest_streak' => $user->longest_streak,
        ];
    }
}
