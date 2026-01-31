<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;

class ResolveSocialUserAction
{
    /**
     * Resolve the user from the social provider.
     */
    public function execute(string $provider, SocialUser $socialUser): User
    {
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

            return $existingUser;
        }

        // Create new user
        // We use create() because all fields are in $fillable in User model
        return User::create([
            'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'Utilisateur',
            'email' => $socialUser->getEmail(),
            'password' => Str::random(16), // Random password, hashed by model cast
            'provider' => $provider,
            'provider_id' => $socialUser->getId(),
            'avatar' => $socialUser->getAvatar(),
            'email_verified_at' => now(), // Assume email is verified by provider
        ]);
    }
}
