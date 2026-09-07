<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SocialAuthException;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

final class HandleSocialCallbackAction
{
    public function __construct(
        protected ResolveSocialUserAction $resolver
    ) {
    }

    /**
     * Handle the social auth callback logic.
     *
     * @throws SocialAuthException
     */
    public function execute(string $fournisseur): User
    {
        try {
            $utilisateurSocial = Socialite::driver($fournisseur)->user();
        } catch (\Exception) {
            throw new SocialAuthException('Erreur lors de la connexion avec '.ucfirst($fournisseur));
        }

        if (! $this->fournisseurAConfirmeLEmail($utilisateurSocial)) {
            if (app()->environment('local')) {
                // SECURITY: Log when email verification is bypassed in local environment
                Log::warning('Social auth email verification bypassed in local environment', [
                    'provider' => $fournisseur,
                    'email' => $utilisateurSocial->getEmail(),
                ]);
            } else {
                throw new SocialAuthException('Votre email n\'est pas vérifié par '.ucfirst($fournisseur));
            }
        }

        return $this->resolver->execute($fournisseur, $utilisateurSocial);
    }

    /**
     * Le fournisseur a-t-il declare l'adresse verifiee ?
     *
     * Tous les fournisseurs ne renseignent pas l'information, et ceux qui le
     * font ne s'accordent pas sur le nom de la cle — d'ou les trois essais.
     * Absente, elle vaut « non verifie » : on ne lie pas un compte sur la foi
     * d'une adresse que personne n'a confirmee.
     *
     * `filter_var()` remplace le test de verite qui etait ici. La valeur vient
     * du fournisseur et n'est donc pas typee : `! $isVerified` etait faux pour
     * n'importe quelle chaine non vide, « false » et « no » compris. Une
     * reponse mal formee ouvrait la porte au lieu de la fermer.
     */
    private function fournisseurAConfirmeLEmail(SocialiteUser $utilisateurSocial): bool
    {
        $brut = $this->attributsBruts($utilisateurSocial);

        return filter_var(
            $brut['email_verified'] ?? $brut['verified_email'] ?? $brut['verified'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Les attributs bruts rendus par le fournisseur.
     *
     * `getRaw()` n'est pas au contrat `Contracts\User`, seulement sur
     * `AbstractUser` — dont tous les pilotes Socialite heritent. Le repli est
     * donc inatteignable en pratique, mais c'est le seul moyen de lire ces
     * attributs sans affirmer au typage une propriete que l'interface ne
     * declare pas. Son mutant est equivalent, pas non couvert.
     *
     * @return array<array-key, mixed>
     *
     * @pest-mutate-ignore
     */
    private function attributsBruts(SocialiteUser $utilisateurSocial): array
    {
        return $utilisateurSocial instanceof AbstractUser ? $utilisateurSocial->getRaw() : [];
    }
}
