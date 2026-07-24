<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Profile\UpdateNotificationPreferencesAction;
use App\Http\Requests\DeleteUserRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing user profiles.
 *
 * This controller handles viewing, editing, updating user profile data,
 * managing notification preferences, and securely handling user account deletion.
 */
class ProfileController extends Controller
{
    /**
     * Display the user's profile menu hub.
     *
     * Renders the main profile navigation index page.
     *
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request.
     * @return \Inertia\Response The Inertia response rendering the 'Profile/Index' page.
     */
    public function index(Request $request): Response
    {
        $this->authorize('view', $this->user());

        return Inertia::render('Profile/Index');
    }

    /**
     * Display the user's profile form.
     *
     * Renders the edit profile page and passes the user's current
     * notification preferences to the frontend.
     *
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request.
     * @return \Inertia\Response The Inertia response rendering the 'Profile/Edit' page with preference data.
     */
    public function edit(Request $request): Response
    {
        $this->authorize('view', $this->user());

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
     *
     * Validates and updates the authenticated user's name and email address.
     * If the email address is changed, it resets the email verification state.
     *
     * @param  \App\Http\Requests\ProfileUpdateRequest  $request  The validated profile update request.
     * @return \Illuminate\Http\RedirectResponse A redirect response back to the profile edit route.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $this->authorize('update', $this->user());

        $this->user()->fill($request->validated());

        if ($this->user()->isDirty('email')) {
            $this->user()->email_verified_at = null;
        }

        $this->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Update the user's notification preferences.
     *
     * Validates and delegates the updating of notification settings (email, push, and values)
     * to the UpdateNotificationPreferencesAction.
     *
     * @param  \App\Http\Requests\UpdateNotificationPreferencesRequest  $request  The validated notification preferences request.
     * @param  \App\Actions\Profile\UpdateNotificationPreferencesAction  $updatePreferences  The action handling the preference updates.
     * @return \Illuminate\Http\RedirectResponse A redirect response back to the profile edit route with a success status.
     */
    public function updatePreferences(UpdateNotificationPreferencesRequest $request, UpdateNotificationPreferencesAction $updatePreferences): RedirectResponse
    {
        $this->authorize('update', $this->user());

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
     *
     * Validates the password, logs the user out, deletes their account
     * data from the database, and invalidates their current session.
     *
     * @param  \App\Http\Requests\DeleteUserRequest  $request  The validated request ensuring user authorization.
     * @return \Illuminate\Http\RedirectResponse A redirect response to the application homepage.
     */
    public function destroy(DeleteUserRequest $request): RedirectResponse
    {
        $this->authorize('delete', $this->user());

        $request->validated();

        $user = $this->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
