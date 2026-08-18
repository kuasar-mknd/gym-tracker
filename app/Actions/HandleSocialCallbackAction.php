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
    public function execute(string $provider): User
    {
        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception) {
            throw new SocialAuthException('Erreur lors de la connexion avec '.ucfirst($provider));
        }

        if (! $this->fournisseurAConfirmeLEmail($socialUser)) {
            if (app()->environment('local')) {
                // SECURITY: Log when email verification is bypassed in local environment
                Log::warning('Social auth email verification bypassed in local environment', [
                    'provider' => $provider,
                    'email' => $socialUser->getEmail(),
                ]);
            } else {
                throw new SocialAuthException('Votre email n\'est pas vérifié par '.ucfirst($provider));
            }
        }

        return $this->resolver->execute($provider, $socialUser);
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
    private function fournisseurAConfirmeLEmail(SocialiteUser $socialUser): bool
    {
        $brut = $this->attributsBruts($socialUser);

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
    private function attributsBruts(SocialiteUser $socialUser): array
    {
        return $socialUser instanceof AbstractUser ? $socialUser->getRaw() : [];
    }
}
