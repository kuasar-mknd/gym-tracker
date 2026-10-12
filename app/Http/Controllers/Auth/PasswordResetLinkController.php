<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetLinkRequest;
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
     * une adresse mal formée reçoit une erreur, celle de sa forme.
     */
    public function store(PasswordResetLinkRequest $request, SendPasswordResetLinkAction $sendPasswordResetLinkAction): RedirectResponse
    {
        $sendPasswordResetLinkAction->execute($request->string('email')->toString());

        return back()->with('status', __('passwords.sent'));
    }
}
