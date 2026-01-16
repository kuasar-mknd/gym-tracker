<?php

namespace App\Http\Controllers\Auth;

use App\Actions\ResolveSocialUserAction;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Redirect the user to the provider authentication page.
     */
    public function redirect($provider)
    {
        return Socialite::driver($provider)->redirect();
    }

    /**
     * Obtain the user information from the provider.
     */
    public function callback(ResolveSocialUserAction $resolver, string $provider)
    {
        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            return redirect()->route('login')->with('status', 'Erreur lors de la connexion avec '.ucfirst($provider));
        }

        // Security check: Ensure email is verified by the provider
        // Note: Not all providers set this, but Socialite attempts to capture it.
        // If not present, we should be cautious about auto-linking.
        $isVerified = $socialUser->user['email_verified'] ?? $socialUser->user['verified_email'] ?? $socialUser->user['verified'] ?? false;

        if (! $isVerified) {
            if (app()->environment('local')) {
                // SECURITY: Log when email verification is bypassed in local environment
                \Illuminate\Support\Facades\Log::warning('Social auth email verification bypassed in local environment', [
                    'provider' => $provider,
                    'email' => $socialUser->getEmail(),
                ]);
            } else {
                return redirect()->route('login')->with('status', 'Votre email n\'est pas vérifié par '.ucfirst($provider));
            }
        }

        $user = $resolver->execute($provider, $socialUser);

        Auth::login($user);

        return redirect()->intended(route('dashboard'));
    }
}
