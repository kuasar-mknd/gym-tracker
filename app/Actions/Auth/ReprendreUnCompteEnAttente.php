<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;

/**
 * Donne au titulaire de l'adresse, revenu par un fournisseur qui la garantit,
 * le compte en attente que l'inscription a ouvert sur elle.
 *
 * Ce compte n'a jamais été ouvert (`AttendLaPreuveDeSonAdresse`), et son
 * inscrit peut être un autre : n'importe qui tape l'adresse d'un autre. Le
 * fournisseur, lui, prouve l'adresse (`ResolveSocialUserAction` a vérifié
 * qu'elle est exactement celle du compte et qu'il la garantit). Le compte
 * passe donc au titulaire : le nom saisi à l'inscription cède celui du
 * fournisseur, le mot de passe choisi par l'inscrit est remplacé par un mot
 * de passe tiré au hasard, que personne ne connaît (comme pour un compte créé
 * par le fournisseur), le jeton « se souvenir de moi » est renouvelé, la
 * liaison est posée prouvée, et l'adresse vérifiée, ce qui ouvre le compte.
 */
final readonly class ReprendreUnCompteEnAttente
{
    public function execute(User $compte, string $fournisseur, string $identifiant, SocialUser $utilisateurSocial): User
    {
        $compte->forceFill([
            'name' => $utilisateurSocial->getName() ?? $utilisateurSocial->getNickname() ?? 'Utilisateur',
            'password' => Str::password(40),
            'remember_token' => Str::random(60),
            'avatar' => $utilisateurSocial->getAvatar(),
            'provider' => $fournisseur,
            'provider_id' => $identifiant,
            'liaison_prouvee_le' => $compte->freshTimestamp(),
        ])->save();

        if ($compte->markEmailAsVerified()) {
            event(new Verified($compte));
        }

        return $compte;
    }
}
