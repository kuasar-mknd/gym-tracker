<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SocialAuthException;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;

final class ResolveSocialUserAction
{
    /**
     * Resolve the user from the social provider.
     */
    public function execute(string $provider, SocialUser $socialUser): User
    {
        // Check if user already exists with this email
        $existingUser = User::where('email', $socialUser->getEmail())->first();

        if ($existingUser) {
            // Security: Prevent account linking if the existing account is not verified.
            // Linking an unverified account via social login poses an account takeover risk.
            if (! $existingUser->hasVerifiedEmail()) {
                throw new SocialAuthException(__('Your account must be verified before linking it with a social provider.'));
            }

            // Update provider info if not set (linking account)
            if (! $existingUser->provider_id) {
                $existingUser->forceFill([
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                ])->update([
                    'avatar' => $socialUser->getAvatar(),
                ]);
            }

            return $existingUser;
        }

        // Create new user
        $user = User::create([
            'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'Utilisateur',
            'email' => $socialUser->getEmail(),
            'password' => bcrypt(Str::random(16)), // Random password since auth is handled by provider
            'avatar' => $socialUser->getAvatar(),
        ]);

        $user->forceFill([
            'provider' => $provider,
            'provider_id' => $socialUser->getId(),
            'email_verified_at' => now(), // Assume email is verified by provider
        ])->save();

        return $user;
    }
}
