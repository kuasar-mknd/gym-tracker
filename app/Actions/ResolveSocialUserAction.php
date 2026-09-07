<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SocialAuthException;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;

final class ResolveSocialUserAction
{
    public function execute(string $provider, SocialUser $socialUser): User
    {
        $existingUser = User::where('email', $socialUser->getEmail())->first();

        if ($existingUser !== null) {
            // Sécurité : pas de rattachement tant que le compte existant n'est
            // pas vérifié. Rattacher un compte non vérifié depuis un fournisseur
            // social ouvre une prise de contrôle du compte.
            if (! $existingUser->hasVerifiedEmail()) {
                throw new SocialAuthException(__('Your account must be verified before linking it with a social provider.'));
            }

            // Non renseigne, et non « vide ou zero » : c'est un identifiant
            // rendu par le fournisseur, la chaine vide n'en est pas un.
            if ($existingUser->provider_id === null || $existingUser->provider_id === '') {
                $existingUser->forceFill([
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                ])->update([
                    'avatar' => $socialUser->getAvatar(),
                ]);
            }

            return $existingUser;
        }

        $user = User::create([
            'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'Utilisateur',
            'email' => $socialUser->getEmail(),
            'password' => bcrypt(Str::random(16)), // Mot de passe aléatoire : c'est le fournisseur qui authentifie.
            'avatar' => $socialUser->getAvatar(),
        ]);

        $user->forceFill([
            'provider' => $provider,
            'provider_id' => $socialUser->getId(),
            'email_verified_at' => now(), // Le fournisseur a déjà vérifié l'adresse.
        ])->save();

        return $user;
    }
}
