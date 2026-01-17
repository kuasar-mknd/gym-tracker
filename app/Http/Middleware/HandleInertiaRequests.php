<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

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
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'avatar' => $request->user()->avatar,
                    'unread_notifications_count' => $request->user()->unreadNotifications()->count(),
                    'latest_achievement' => $request->user()->unreadNotifications()
                        ->where('type', 'App\Notifications\AchievementUnlocked')
                        ->latest()
                        ->first(),
                    'current_streak' => $request->user()->current_streak,
                    'longest_streak' => $request->user()->longest_streak,
                ] : null,
            ],
            'is_testing' => app()->environment('testing'),
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            'ziggy' => fn () => [
                (new \Tighten\Ziggy\Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }
}
