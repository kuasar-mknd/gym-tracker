<?php

namespace App\Actions;

use App\Exceptions\SocialAuthException;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class HandleSocialCallbackAction
{
    public function __construct(
        protected ResolveSocialUserAction $resolver
    ) {}

    /**
     * Handle the social auth callback logic.
     *
     * @throws SocialAuthException
     */
    public function execute(string $provider): User
    {
        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            throw new SocialAuthException('Erreur lors de la connexion avec ' . ucfirst($provider));
        }

        // Security check: Ensure email is verified by the provider
        // Note: Not all providers set this, but Socialite attempts to capture it.
        // If not present, we should be cautious about auto-linking.
        $isVerified = $socialUser->user['email_verified']
            ?? $socialUser->user['verified_email']
            ?? $socialUser->user['verified']
            ?? false;

        if (! $isVerified) {
            if (app()->environment('local')) {
                // SECURITY: Log when email verification is bypassed in local environment
                Log::warning('Social auth email verification bypassed in local environment', [
                    'provider' => $provider,
                    'email' => $socialUser->getEmail(),
                ]);
            } else {
                throw new SocialAuthException('Votre email n\'est pas vérifié par ' . ucfirst($provider));
            }
        }

        return $this->resolver->execute($provider, $socialUser);
    }
}
