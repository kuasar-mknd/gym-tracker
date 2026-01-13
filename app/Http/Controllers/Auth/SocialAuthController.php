<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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
    public function callback($provider)
    {
        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            return redirect()->route('login')->with('status', 'Erreur lors de la connexion avec '.ucfirst($provider));
        }

        // Check if user already exists with this email
        $existingUser = User::where('email', $socialUser->getEmail())->first();

        if ($existingUser) {
            // Update provider info if not set (linking account)
            if (! $existingUser->provider_id) {
                $existingUser->update([
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                    'avatar' => $socialUser->getAvatar(),
                ]);
            }

            Auth::login($existingUser);
        } else {
            // Create new user
            $newUser = User::create([
                'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'Utilisateur',
                'email' => $socialUser->getEmail(),
                'password' => bcrypt(Str::random(16)), // Random password since auth is handled by provider
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'avatar' => $socialUser->getAvatar(),
                'email_verified_at' => now(), // Assume email is verified by provider
            ]);

            Auth::login($newUser);
        }

        return redirect()->intended(route('dashboard'));
    }
}
