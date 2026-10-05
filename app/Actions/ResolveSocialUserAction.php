<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SocialAuthException;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;

final class ResolveSocialUserAction
{
    public function execute(string $fournisseur, SocialUser $utilisateurSocial): User
    {
        $existingUser = User::where('email', $utilisateurSocial->getEmail())->first();

        if ($existingUser !== null) {
            // Sécurité : pas de rattachement tant que le compte existant n'est
            // pas vérifié. Rattacher un compte non vérifié depuis un fournisseur
            // social ouvre une prise de contrôle du compte.
            if (! $existingUser->hasVerifiedEmail()) {
                if (! $this->estLIdentiteDejaReliee($existingUser, $fournisseur, $utilisateurSocial)) {
                    throw new SocialAuthException(__('Your account must be verified before linking it with a social provider.'));
                }

                $existingUser->markEmailAsVerified();
            }

            // Non renseigne, et non « vide ou zero » : c'est un identifiant
            // rendu par le fournisseur, la chaine vide n'en est pas un.
            if ($existingUser->provider_id === null || $existingUser->provider_id === '') {
                $existingUser->forceFill([
                    'provider' => $fournisseur,
                    'provider_id' => $utilisateurSocial->getId(),
                ])->update([
                    'avatar' => $utilisateurSocial->getAvatar(),
                ]);
            }

            return $existingUser;
        }

        $user = User::create([
            'name' => $utilisateurSocial->getName() ?? $utilisateurSocial->getNickname() ?? 'Utilisateur',
            'email' => $utilisateurSocial->getEmail(),
            'password' => bcrypt(Str::random(16)), // Mot de passe aléatoire : c'est le fournisseur qui authentifie.
            'avatar' => $utilisateurSocial->getAvatar(),
        ]);

        $user->forceFill([
            'provider' => $fournisseur,
            'provider_id' => $utilisateurSocial->getId(),
            'email_verified_at' => now(), // Le fournisseur a déjà vérifié l'adresse.
        ])->save();

        return $user;
    }

    /**
     * Le retour vient-il de l'identité déjà reliée au compte, pour l'adresse
     * même du compte ?
     *
     * Un compte relié à un fournisseur repasse non vérifié quand son adresse
     * change, par le profil ou par le panneau (`SurveilleSonAdresse`). Quand
     * c'est l'identité qu'il connaît déjà qui revient, et que le fournisseur
     * garantit l'adresse du compte (hors environnement local,
     * `HandleSocialCallbackAction` refuse un retour dont l'adresse n'est pas
     * garantie), l'adresse est prouvée : le compte s'ouvre et redevient
     * vérifié. Un compte que cette identité n'a jamais ouvert reste refusé tant
     * qu'il n'est pas vérifié.
     *
     * La base a trouvé le compte selon sa collation, qui tient pour égales des
     * adresses seulement proches : l'adresse se compare donc ici octet par
     * octet, à la casse ASCII près, et c'est bien celle du compte que le
     * fournisseur doit garantir. L'identifiant se compare en chaîne, GitHub le
     * rendant en entier.
     */
    private function estLIdentiteDejaReliee(User $compte, string $fournisseur, SocialUser $utilisateurSocial): bool
    {
        $identifiant = $utilisateurSocial->getId();
        $adresse = $utilisateurSocial->getEmail();

        if (is_int($identifiant)) {
            $identifiant = (string) $identifiant;
        }

        return $compte->provider === $fournisseur
            && is_string($compte->provider_id)
            && $compte->provider_id !== ''
            && $identifiant === $compte->provider_id
            && is_string($adresse)
            && strtolower($adresse) === strtolower($compte->email);
    }
}
