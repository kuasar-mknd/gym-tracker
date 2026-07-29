<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\NotificationService;
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
    #[\Override]
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $this->getUserData($request),
            ],
            'flash' => [
                'success' => $request->hasSession() ? $request->session()->get('success') : null,
                'error' => $request->hasSession() ? $request->session()->get('error') : null,
            ],
            // CI builds its Dusk env with APP_ENV=testing but .env.dusk.local
            // keeps APP_ENV=local, so the environment check alone held on CI and
            // silently did not locally — where the celebration overlay this flag
            // exists to suppress fired mid-test and swallowed clicks. The
            // explicit flag makes both runs agree.
            'is_testing' => app()->environment('testing') || config('app.running_browser_tests') === true,
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            'ziggy' => function () use ($request): array {
                $ziggy = new Ziggy();

                return [
                    ...$ziggy->toArray(),
                    'location' => $request->url(),
                ];
            },
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

        $notificationService = app(NotificationService::class);

        $latestAchievement = $notificationService->getLatestAchievement($user);

        $activeWorkout = \Illuminate\Support\Facades\Cache::remember(
            "user_active_workout_{$user->id}",
            now()->addHours(2),
            fn () => $user->workouts()
                ->select('id', 'name', 'started_at')
                ->whereNull('ended_at')
                ->withCount('workoutLines')
                ->latest('started_at')
                ->first()
        );

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'unread_notifications_count' => $notificationService->getUnreadCount($user),
            'latest_achievement' => $latestAchievement ? [
                'id' => $latestAchievement->id,
                'data' => $latestAchievement->data,
                'created_at' => $latestAchievement->created_at,
            ] : null,
            'current_streak' => $user->last_workout_at && $user->last_workout_at->startOfDay()->diffInDays(now()->startOfDay()) > 1 ? 0 : $user->current_streak,
            'longest_streak' => $user->longest_streak,
            'active_workout' => $activeWorkout,
        ];
    }
}
