<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile menu hub.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Profile/Index');
    }

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => true,
            'status' => session('status'),
            'notificationPreferences' => $this->user()->notificationPreferences()->get()->mapWithKeys(fn ($pref): array => [
                $pref->type => [
                    'is_enabled' => $pref->is_enabled,
                    'is_push_enabled' => $pref->is_push_enabled,
                    'value' => $pref->value,
                ],
            ]),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $this->user()->fill($request->validated());

        if ($this->user()->isDirty('email')) {
            $this->user()->email_verified_at = null;
        }

        $this->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Update the user's notification preferences.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $allowedTypes = [
            'daily_reminder',
            'workout_streak_reminder',
            'no_activity_reminder',
            'weekly_summary',
            'achievement_unlocked',
            'goal_progress',
        ];

        $validated = $request->validate([
            'preferences' => ['required', 'array', 'bail', function ($attribute, $value, $fail) use ($allowedTypes) {
                $keys = array_keys($value);
                $diff = array_diff($keys, $allowedTypes);
                if (! empty($diff)) {
                    $fail('Invalid preference types: '.implode(', ', $diff));
                }
            }],
            'preferences.*' => ['boolean'],
            'push_preferences' => ['required', 'array'],
            'push_preferences.*' => ['boolean'],
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        foreach ($validated['preferences'] as $type => $isEnabled) {
            $this->user()->notificationPreferences()->updateOrCreate(
                ['type' => $type],
                [
                    'is_enabled' => $isEnabled,
                    'is_push_enabled' => $validated['push_preferences'][$type] ?? false,
                    'value' => $validated['values'][$type] ?? null,
                ]
            );
        }

        return Redirect::route('profile.edit')->with('status', 'notification-preferences-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $this->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
