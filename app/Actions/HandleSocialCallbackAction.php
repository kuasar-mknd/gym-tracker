<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SocialAuthException;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        } catch (\Exception $exception) {
            $this->journaliserLEchecDuFournisseur($fournisseur, $exception);

            throw new SocialAuthException('Erreur lors de la connexion avec '.ucfirst($fournisseur));
        }

        $adresseVerifiee = $this->fournisseurAConfirmeLEmail($fournisseur, $utilisateurSocial);

        if (! $adresseVerifiee) {
            if (app()->environment('local')) {
                // Sécurité : garder la trace du contournement, permis en local seulement.
                Log::warning('Social auth email verification bypassed in local environment', [
                    'provider' => $fournisseur,
                    'email' => $utilisateurSocial->getEmail(),
                ]);
            } else {
                throw new SocialAuthException('Votre email n\'est pas vérifié par '.ucfirst($fournisseur));
            }
        }

        return $this->resolver->execute($fournisseur, $utilisateurSocial, $adresseVerifiee);
    }

    /**
     * Garde la trace d'un échange refusé par le fournisseur.
     *
     * L'utilisateur ne voit qu'« Erreur lors de la connexion », et l'exception
     * était avalée : un `redirect_uri_mismatch` ou un `invalid_client` de
     * production ne laissait rien derrière lui (#1908). Le message du
     * fournisseur dit la cause ; il ne porte ni jeton ni code d'autorisation.
     */
    private function journaliserLEchecDuFournisseur(string $fournisseur, \Exception $exception): void
    {
        Log::warning('Connexion sociale refusée par le fournisseur', [
            'fournisseur' => $fournisseur,
            'exception' => $exception::class,
            'message' => Str::limit($exception->getMessage(), 500),
        ]);
    }

    /**
     * Le fournisseur a-t-il declare l'adresse verifiee ?
     *
     * GitHub ne le declare pas : le pilote de Socialite demande la portee
     * `user:email` et ne rend que l'adresse principale ET verifiee du compte,
     * ou null quand il n'y en a pas. Une adresse rendue par GitHub est donc
     * verifiee par construction ; ses attributs bruts, ceux de `/user`, ne
     * portent aucune cle de verification, et la recherche ci-dessous refusait
     * tout retour de GitHub hors du poste de developpement.
     *
     * Google (`email_verified` de son point d'information) et Apple
     * (`email_verified` du jeton d'identite) le declarent, mais pas sous le
     * meme nom que d'autres fournisseurs — d'ou les trois essais. Absente, la
     * cle vaut « non verifie » : on ne lie pas un compte sur la foi d'une
     * adresse que personne n'a confirmee.
     *
     * `filter_var()` remplace le test de verite qui etait ici. La valeur vient
     * du fournisseur et n'est donc pas typee : `! $isVerified` etait faux pour
     * n'importe quelle chaine non vide, « false » et « no » compris. Une
     * reponse mal formee ouvrait la porte au lieu de la fermer.
     */
    private function fournisseurAConfirmeLEmail(string $fournisseur, SocialiteUser $utilisateurSocial): bool
    {
        if ($fournisseur === 'github') {
            $adresse = $utilisateurSocial->getEmail();

            return is_string($adresse) && $adresse !== '';
        }

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
