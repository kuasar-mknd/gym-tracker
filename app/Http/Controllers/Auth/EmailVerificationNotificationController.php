<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     *
     * Une adresse que Symfony Mime ne sait pas écrire ne reçoit pas de lien
     * (`User::sendEmailVerificationNotification()`, #2075) : la page le dit,
     * plutôt qu'un envoi qui n'a pas eu lieu, et renvoie au profil, ouvert
     * sans adresse vérifiée, pour en changer.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($this->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        if (! $this->user()->laMessagerieSaitEcrireSonAdresse()) {
            return back()->with('status', 'adresse-que-la-messagerie-n-ecrit-pas');
        }

        $this->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
