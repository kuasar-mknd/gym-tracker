<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\HandleSocialCallbackAction;
use App\Exceptions\SocialAuthException;
use App\Http\Controllers\Controller;
use App\Support\ConnexionSociale\FournisseurApple;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public const ALLOWED_PROVIDERS = ['github', 'google', 'apple'];

    /**
     * Envoie l'utilisateur sur la page d'authentification du fournisseur.
     */
    public function redirect(string $fournisseur): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (! in_array($fournisseur, self::ALLOWED_PROVIDERS, true)) {
            abort(404);
        }

        return Socialite::driver($fournisseur)->redirect();
    }

    /**
     * Récupère l'utilisateur auprès du fournisseur et le connecte.
     */
    public function callback(HandleSocialCallbackAction $action, string $fournisseur): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (! in_array($fournisseur, self::ALLOWED_PROVIDERS, true)) {
            abort(404);
        }
        try {
            $user = $action->execute($fournisseur);
        } catch (SocialAuthException $e) {
            return $this->oublierLeNonce($fournisseur, redirect()->route('login')->with('status', $e->getMessage()));
        }

        Auth::login($user);

        return $this->oublierLeNonce($fournisseur, redirect()->intended(route('dashboard')));
    }

    /**
     * Le nonce d'Apple a servi : le navigateur ne le garde pas.
     */
    private function oublierLeNonce(string $fournisseur, RedirectResponse $reponse): RedirectResponse
    {
        if ($fournisseur === 'apple') {
            $reponse->headers->setCookie(FournisseurApple::cookieDuNonceOublie());
        }

        return $reponse;
    }
}
