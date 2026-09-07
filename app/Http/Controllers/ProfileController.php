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

class ProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('view', $this->user());

        return Inertia::render('Profile/Index');
    }

    public function edit(Request $request): Response
    {
        $this->authorize('view', $this->user());

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => true,
            'status' => session('status'),
            // Est-ce que NOUS détenons un abonnement, ce qui n'est pas la même
            // chose que le navigateur ayant accordé la permission. La page
            // déduisait l'un de l'autre : un abonnement que le serveur n'avait
            // jamais enregistré basculait quand même l'interface en « push
            // activé ».
            'hasPushSubscription' => $this->user()->pushSubscriptions()->exists(),
            'notificationPreferences' => $this->user()->notificationPreferences()->get()->mapWithKeys(fn ($pref): array => [
                $pref->type => [
                    'is_enabled' => $pref->is_enabled,
                    'is_push_enabled' => $pref->is_push_enabled,
                    'value' => $pref->value,
                    'days' => $pref->days,
                ],
            ]),
        ]);
    }

    /**
     * Changer d'adresse annule la vérification : sans quoi n'importe qui
     * pourrait se donner une adresse déjà marquée comme vérifiée.
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
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response 204 pour un client XHR, sinon retour au profil.
     */
    public function updatePreferences(UpdateNotificationPreferencesRequest $request, UpdateNotificationPreferencesAction $updatePreferences): \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
    {
        $this->authorize('update', $this->user());

        /**
         * @var array{
         *     preferences: array<string, bool>,
         *     push_preferences?: array<string, bool>,
         *     values?: array<string, mixed>
         * } $donneesValidees
         */
        $donneesValidees = $request->validated();

        $updatePreferences->execute($this->user(), $donneesValidees);

        // Un client XHR qui suivrait la redirection rejouerait le PATCH sur
        // /profile/edit (seul un 303 force GET) et recevrait un 405.
        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return Redirect::route('profile.edit')->with('status', 'notification-preferences-updated');
    }

    /**
     * Bascule le démarrage automatique du minuteur de repos.
     *
     * Renvoie en arrière plutôt que vers le profil : l'interrupteur vit dans le
     * panneau du minuteur, donc pendant une séance. Un redirect vers
     * `profile.edit` sortirait l'utilisateur de sa séance pour un basculement.
     */
    public function updateRestTimerPreference(\App\Http\Requests\UpdateRestTimerPreferenceRequest $request): RedirectResponse
    {
        $this->authorize('update', $this->user());

        $this->user()->update(['auto_rest_timer' => $request->boolean('auto_rest_timer')]);

        return back();
    }

    /**
     * L'utilisateur est déconnecté avant d'être supprimé, et la session est
     * invalidée après : le mot de passe est vérifié par `DeleteUserRequest`.
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
