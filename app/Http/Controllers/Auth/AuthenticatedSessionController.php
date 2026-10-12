<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\DureeDesReponsesDAuthentification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Timebox;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Connecte le compte, ou refuse, en au moins le plancher commun des réponses
     * d'authentification (`DureeDesReponsesDAuthentification`) : la
     * vérification du mot de passe d'une adresse connue (bcrypt) dépassait la
     * Timebox de la garde, et un refus durait plus longtemps qu'une adresse
     * inconnue. Seule une connexion réussie rend la main plus tôt.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        DureeDesReponsesDAuthentification::appeler(static function (Timebox $delai) use ($request): void {
            $request->authenticate();

            $request->session()->regenerate();

            $delai->returnEarly();
        });

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     *
     * La première page servie ensuite porte `clearHistory` (#1965) : Inertia y
     * jette la clé de l'historique, et les pages du compte qu'il gardait ne se
     * déchiffrent plus au bouton Retour. Posé après `invalidate()`, qui vide la
     * session : avant, la consigne partirait avec elle.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        Inertia::clearHistory();

        return redirect('/');
    }
}
