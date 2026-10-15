<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetLinkRequest;
use App\Support\DureeDesReponsesDAuthentification;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Répond la même chose à toute adresse bien formée, qu'elle ait un compte
     * ou non, et que sa dernière demande soit récente ou non (#1951) : seule
     * une adresse mal formée reçoit une erreur, celle de sa forme. La demande
     * ne fait que mettre l'envoi en file, et dure le plancher commun des
     * réponses d'authentification (`DureeDesReponsesDAuthentification`).
     */
    public function store(PasswordResetLinkRequest $request, SendPasswordResetLinkAction $sendPasswordResetLinkAction): RedirectResponse
    {
        $adresse = $request->string('email')->toString();

        DureeDesReponsesDAuthentification::appeler(static function () use ($sendPasswordResetLinkAction, $adresse): void {
            $sendPasswordResetLinkAction->execute($adresse);
        });

        return back()->with('status', __('passwords.sent'));
    }
}
