<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Profile\ActiverLesEnvoisPushAction;
use App\Actions\Profile\UpdateNotificationPreferencesAction;
use App\Http\Requests\ActiverLesEnvoisPushRequest;
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
            // Le formulaire d'adresse explique à un compte relié à un
            // fournisseur comment obtenir le mot de passe qu'il exige.
            'fournisseurDeConnexion' => $this->user()->fournisseurDeConnexion(),
            // `auth.user` ne dit pas si l'adresse est vérifiée : le formulaire
            // en tire l'annonce de l'avis à l'adresse actuelle et le bandeau
            // qui propose de renvoyer le lien de vérification.
            'adresseVerifiee' => $this->user()->hasVerifiedEmail(),
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
     * Le mot de passe actuel, exigé quand l'adresse change, est vérifié par
     * `ProfileUpdateRequest` et n'est pas écrit. Le reste suit tout changement
     * d'adresse, quel que soit le chemin (`SurveilleSonAdresse`) : la nouvelle
     * adresse repasse non vérifiée, et l'ancienne en est prévenue.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $this->authorize('update', $this->user());

        /** @var array{name: string, email: string} $donneesValidees */
        $donneesValidees = $request->safe()->only(['name', 'email']);

        $this->user()->update($donneesValidees);

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
     * Allume l'envoi push des types demandés, sans toucher au reste (#1848).
     *
     * L'invitation de fin de séance l'appelle en XHR, une fois l'appareil
     * abonné : sans elle, l'abonnement serait pris et aucun record ne partirait
     * en push. D'où un 204 et jamais de redirection (.ai/rules/controllers.md).
     */
    public function activerLesEnvoisPush(ActiverLesEnvoisPushRequest $request, ActiverLesEnvoisPushAction $activerLesEnvoisPush): \Illuminate\Http\Response
    {
        $this->authorize('update', $this->user());

        $activerLesEnvoisPush->execute($this->user(), $request->types());

        return response()->noContent();
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
     * Le compte est supprimé, puis l'utilisateur déconnecté et la session
     * invalidée ; le mot de passe est vérifié par `DeleteUserRequest`.
     *
     * La déconnexion vient après la suppression (#1982) : une suppression qui
     * échoue laisse l'utilisateur connecté devant une erreur, au lieu de le
     * déconnecter avec un compte resté en base qu'il croirait effacé.
     *
     * `SessionGuard::logout()` fait tourner le jeton « se souvenir de moi »
     * par un `save()` du compte, et `save()` sur un modèle supprimé l'insère
     * de nouveau. Le jeton est donc oublié en mémoire, sans écriture, juste
     * avant : il n'y a plus rien à faire tourner, le compte qui le portait
     * n'existe plus. Le témoin « se souvenir de moi » du navigateur, lui, est
     * retiré par la déconnexion.
     */
    public function destroy(DeleteUserRequest $request): RedirectResponse
    {
        $this->authorize('delete', $this->user());

        $request->validated();

        $user = $this->user();

        $user->delete();

        $user->setRememberToken('');
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Comme à la déconnexion : l'historique du compte supprimé ne se relit plus (#1965).
        Inertia::clearHistory();

        return Redirect::to('/');
    }
}
