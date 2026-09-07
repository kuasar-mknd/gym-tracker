<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\HandleSocialCallbackAction;
use App\Exceptions\SocialAuthException;
use App\Http\Controllers\Controller;
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
            return redirect()->route('login')->with('status', $e->getMessage());
        }

        Auth::login($user);

        return redirect()->intended(route('dashboard'));
    }
}
