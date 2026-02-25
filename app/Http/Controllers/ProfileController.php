<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Profile\UpdateNotificationPreferencesAction;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
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
    public function updatePreferences(UpdateNotificationPreferencesRequest $request, UpdateNotificationPreferencesAction $updatePreferences): RedirectResponse
    {
        /**
         * @var array{
         *     preferences: array<string, bool>,
         *     push_preferences?: array<string, bool>,
         *     values?: array<string, mixed>
         * } $validated
         */
        $validated = $request->validated();

        $updatePreferences->execute($this->user(), $validated);

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
