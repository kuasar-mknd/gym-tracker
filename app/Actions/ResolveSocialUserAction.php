<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SocialAuthException;
use App\Models\User;
use App\Rules\AdresseEnAsciiImprimable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;

/**
 * Retrouve, rattache ou crée le compte d'un retour de connexion sociale.
 *
 * Un compte existant ne s'ouvre que pour son adresse : celle que rend le
 * fournisseur doit être la sienne, à la casse ASCII près.
 *
 *  1. Un compte qui porte déjà l'identité du fournisseur (`provider`,
 *     `provider_id`) s'ouvre à cette condition, et l'identité seule ne suffit
 *     pas. L'adresse du compte a pu changer depuis la liaison, par le profil
 *     ou par le panneau, et l'ancienne recherche par adresse a pu poser une
 *     liaison sur une adresse seulement proche : le titulaire de l'identité
 *     n'est alors peut-être pas celui du compte. Rien en base ne garde
 *     l'adresse de la liaison, et rien ne distingue donc ces liaisons des
 *     autres. Une identité qui rend une autre adresse que celle de son compte
 *     est refusée, sans qu'un compte soit créé à la place. Quand elle rend
 *     l'adresse du compte et que le fournisseur la garantit, le compte
 *     redevient vérifié s'il ne l'était plus : un changement d'adresse, par
 *     le profil ou par le panneau, retire la vérification
 *     (`SurveilleSonAdresse`).
 *  2. À défaut, l'adresse ne rattache un compte existant que si elle est en
 *     ASCII imprimable, vérifiée par le fournisseur, et que le compte n'est
 *     lié à aucune autre identité du même fournisseur.
 *  3. Sinon, un compte est créé, à condition qu'aucun compte n'occupe déjà une
 *     adresse que la base tiendrait pour la même.
 *
 * Toute comparaison d'adresse et d'identifiant se fait en PHP, octet par
 * octet. La base ne sert qu'à trouver les candidats : sa collation
 * (`utf8mb4_unicode_ci`) ignore accents et casse, replie « ß » sur « ss », le
 * signe kelvin sur « k », les formes pleine chasse sur l'ASCII, et néglige les
 * espaces finales.
 *
 * Chaque refus propose une issue qui existe : quand un compte occupe
 * l'adresse, la connexion par mot de passe, et jamais l'inscription, que
 * l'index unique de la même collation refuserait.
 */
final class ResolveSocialUserAction
{
    /**
     * @param  bool  $adresseVerifiee  Le fournisseur garantit-il l'adresse rendue ? Faux, elle ne rattache aucun compte existant et le compte créé naît non vérifié.
     *
     * @throws SocialAuthException quand le retour ne désigne aucun compte sans ambiguïté.
     */
    public function execute(string $fournisseur, SocialUser $utilisateurSocial, bool $adresseVerifiee): User
    {
        $identifiant = $this->identifiantDuFournisseur($utilisateurSocial);

        if ($identifiant === null) {
            throw new SocialAuthException('Erreur lors de la connexion avec '.ucfirst($fournisseur));
        }

        $adresse = $utilisateurSocial->getEmail();

        if (! is_string($adresse) || $adresse === '') {
            throw new SocialAuthException(ucfirst($fournisseur).' ne nous a transmis aucune adresse email. Connectez-vous avec votre email et votre mot de passe, ou inscrivez-vous.');
        }

        $comptesDeLIdentite = $this->comptesDeLIdentite($fournisseur, $identifiant);

        if ($comptesDeLIdentite->isNotEmpty()) {
            return $this->compteDeLIdentite($comptesDeLIdentite, $fournisseur, $adresse, $adresseVerifiee);
        }

        $compteDeLAdresse = User::query()->where('email', $adresse)->first();

        if ($this->adresseComparable($adresse) === null) {
            throw new SocialAuthException($compteDeLAdresse === null
                ? $this->refusDAdresseHorsAscii($fournisseur)
                : $this->refusDAdresseProche($fournisseur));
        }

        if ($compteDeLAdresse === null) {
            return $this->creerLeCompte($fournisseur, $identifiant, $utilisateurSocial, $adresse, $adresseVerifiee);
        }

        return $this->rattacher($compteDeLAdresse, $fournisseur, $identifiant, $utilisateurSocial, $adresse, $adresseVerifiee);
    }

    /**
     * Les comptes qui portent exactement cette identité du fournisseur.
     *
     * La base rend les candidats selon sa collation, qui ne distingue pas
     * « AbC » de « abc » : le filtre exact se fait ici.
     *
     * @return Collection<int, User>
     */
    private function comptesDeLIdentite(string $fournisseur, string $identifiant): Collection
    {
        return User::query()
            ->where('provider', $fournisseur)
            ->where('provider_id', $identifiant)
            ->orderBy('id')
            ->get()
            ->filter(static fn (User $compte): bool => $compte->provider === $fournisseur && $compte->provider_id === $identifiant)
            ->values();
    }

    /**
     * Le compte de cette identité dont l'adresse est celle rendue, ou un refus.
     *
     * Plusieurs comptes peuvent porter la même identité, l'ancienne recherche
     * par adresse en créant un second quand l'adresse changeait chez le
     * fournisseur : le retour va à celui dont l'adresse est celle rendue.
     * Aucun ne l'a, le retour est refusé et journalisé, sans l'adresse.
     *
     * Le compte trouvé redevient vérifié quand le fournisseur garantit
     * l'adresse : c'est la sienne, prouvée par l'identité qui l'a déjà
     * ouvert. Sans quoi le titulaire d'un compte ouvert par un fournisseur,
     * qui ne connaît pas le mot de passe tiré au hasard, resterait dehors
     * après que le panneau lui a rendu son adresse. `markEmailAsVerified()`
     * vide aussi la dernière adresse vérifiée retenue
     * (`ancienne_adresse_verifiee`).
     *
     * @param  Collection<int, User>  $comptes
     *
     * @throws SocialAuthException
     */
    private function compteDeLIdentite(Collection $comptes, string $fournisseur, string $adresse, bool $adresseVerifiee): User
    {
        $compte = $comptes->first(fn (User $candidat): bool => $this->memeAdresse($candidat->email, $adresse));

        if ($compte !== null) {
            if ($adresseVerifiee && ! $compte->hasVerifiedEmail()) {
                $compte->markEmailAsVerified();
            }

            return $compte;
        }

        Log::warning('Connexion sociale refusée : l’identité rend une autre adresse que celle de son compte', [
            'fournisseur' => $fournisseur,
            'comptes' => $comptes->modelKeys(),
        ]);

        throw new SocialAuthException('Ce compte '.ucfirst($fournisseur).' est associé à un compte dont l\'adresse email n\'est pas celle que '.ucfirst($fournisseur).' nous transmet. '.$this->versLeMotDePasse('l\'adresse email de ce compte'));
    }

    /**
     * Rattache le retour au compte qui occupe son adresse, ou le refuse.
     *
     * Le compte a été trouvé par la base, donc selon sa collation : il peut ne
     * porter qu'une adresse proche. Il n'est rattaché que si :
     *
     *  - son adresse est exactement celle rendue, à la casse ASCII près ;
     *  - le fournisseur garantit cette adresse ;
     *  - le compte a lui-même vérifié son adresse (un compte non vérifié a pu
     *    être ouvert par quelqu'un qui ne détient pas la boîte) ;
     *  - il n'est pas déjà lié à une autre identité du même fournisseur.
     *
     * Un refus ne crée pas de compte à la place : l'index unique de la base, de
     * la même collation, tient les deux adresses pour une seule. Un compte lié
     * à un autre fournisseur s'ouvre sans que sa liaison soit réécrite.
     *
     * @throws SocialAuthException
     */
    private function rattacher(
        User $compte,
        string $fournisseur,
        string $identifiant,
        SocialUser $utilisateurSocial,
        string $adresse,
        bool $adresseVerifiee,
    ): User {
        if (! $this->memeAdresse($compte->email, $adresse)) {
            Log::warning('Connexion sociale refusée : adresse seulement proche de celle d’un compte', [
                'fournisseur' => $fournisseur,
                'compte' => $compte->getKey(),
            ]);

            throw new SocialAuthException($this->refusDAdresseProche($fournisseur));
        }

        if (! $adresseVerifiee) {
            throw new SocialAuthException('Votre email n\'est pas vérifié par '.ucfirst($fournisseur));
        }

        // Sécurité : pas de rattachement tant que le compte existant n'est
        // pas vérifié. Rattacher un compte non vérifié depuis un fournisseur
        // social ouvre une prise de contrôle du compte.
        if (! $compte->hasVerifiedEmail()) {
            throw new SocialAuthException(__('Your account must be verified before linking it with a social provider.'));
        }

        // Non renseigne, et non « vide ou zero » : c'est un identifiant
        // rendu par le fournisseur, la chaine vide n'en est pas un.
        $dejaLie = $compte->provider_id !== null && $compte->provider_id !== '';

        if ($dejaLie && $compte->provider === $fournisseur) {
            Log::warning('Connexion sociale refusée : compte lié à une autre identité du même fournisseur', [
                'fournisseur' => $fournisseur,
                'compte' => $compte->getKey(),
            ]);

            throw new SocialAuthException('Un compte existe déjà avec cette adresse email, associé à un autre compte '.ucfirst($fournisseur).'. '.$this->versLeMotDePasse('cette adresse'));
        }

        if (! $dejaLie) {
            $compte->forceFill([
                'provider' => $fournisseur,
                'provider_id' => $identifiant,
            ])->update([
                'avatar' => $utilisateurSocial->getAvatar(),
            ]);
        }

        return $compte;
    }

    /**
     * Crée le compte du retour, sur l'adresse telle que le fournisseur la rend.
     *
     * L'adresse est ici en ASCII imprimable (`adresseComparable()` l'a admise) :
     * sa casse est la seule liberté qu'elle garde, et `memeAdresse()` comme
     * l'index unique l'ignorent, si bien que le retour suivant reconnaît le
     * compte quelle que soit la casse rendue. Le profil n'exige les minuscules
     * que d'une adresse qui change (`ProfileUpdateRequest`) : le compte
     * enregistre son nom sans toucher à son adresse.
     */
    private function creerLeCompte(
        string $fournisseur,
        string $identifiant,
        SocialUser $utilisateurSocial,
        string $adresse,
        bool $adresseVerifiee,
    ): User {
        $user = User::create([
            'name' => $utilisateurSocial->getName() ?? $utilisateurSocial->getNickname() ?? 'Utilisateur',
            'email' => $adresse,
            'password' => bcrypt(Str::random(16)), // Mot de passe aléatoire : c'est le fournisseur qui authentifie.
            'avatar' => $utilisateurSocial->getAvatar(),
        ]);

        $user->forceFill([
            'provider' => $fournisseur,
            'provider_id' => $identifiant,
            'email_verified_at' => $adresseVerifiee ? now() : null, // Vérifiée seulement si le fournisseur la garantit.
        ])->save();

        return $user;
    }

    /**
     * L'identifiant rendu par le fournisseur, en chaîne.
     *
     * Le contrat le dit `string`, mais GitHub rend un entier : c'est la
     * colonne, une chaîne, qui fixe la forme comparée. Sans identifiant, le
     * retour ne pourrait pas être reconnu à la connexion suivante.
     */
    private function identifiantDuFournisseur(SocialUser $utilisateurSocial): ?string
    {
        $identifiant = $utilisateurSocial->getId();

        if (is_int($identifiant)) {
            return (string) $identifiant;
        }

        return is_string($identifiant) && $identifiant !== '' ? $identifiant : null;
    }

    /**
     * Les deux adresses sont-elles la même ?
     *
     * Oui si elles sont identiques octet par octet, ou si elles ont une forme
     * comparable et que ces formes sont égales. Jamais selon la collation de
     * la base.
     */
    private function memeAdresse(string $adresseDuCompte, string $adresseRendue): bool
    {
        if ($adresseDuCompte === $adresseRendue) {
            return true;
        }

        $adresseComparable = $this->adresseComparable($adresseRendue);

        return $adresseComparable !== null && $adresseComparable === $this->adresseComparable($adresseDuCompte);
    }

    /**
     * La forme sous laquelle deux adresses se comparent, ou null si elle n'en a pas.
     *
     * Seule une adresse en ASCII imprimable, sans espace, en a une : ses
     * lettres en minuscules, par `strtolower()`, qui ne touche que les
     * vingt-six lettres ASCII depuis PHP 8.2. C'est le seul repli que l'index
     * unique de la base fait aussi sur l'ASCII, et le seul qu'on lui emprunte.
     *
     * Une adresse non ASCII ne rattache aucun compte plutôt que d'être
     * normalisée. NFC replierait lui-même le signe kelvin (U+212A) sur « K »,
     * sa décomposition canonique, et créerait l'égalité qu'on veut éviter ; il
     * laisse en revanche les formes pleine chasse et le s long, que la base
     * confond avec l'ASCII, et NFKC, qui les replie, garde « ß » quand la base
     * le tient pour « ss » : aucune forme normale ne reproduit la collation.
     * `mb_strtolower()` et le repli de casse Unicode créent eux aussi des
     * égalités (le signe kelvin y devient « k »). Et un domaine
     * internationalisé s'écrit de deux façons (Unicode ou Punycode) que rien
     * ici ne saurait apparier. Les trois fournisseurs rendent de l'ASCII pour
     * l'immense majorité des comptes, et l'inscription comme le profil n'en
     * admettent pas d'autre (`AdresseEnAsciiImprimable`, même motif).
     */
    private function adresseComparable(string $adresse): ?string
    {
        if (preg_match(AdresseEnAsciiImprimable::MOTIF, $adresse) !== 1) {
            return null;
        }

        return strtolower($adresse);
    }

    /**
     * Le refus d'une adresse qu'un compte occupe aux yeux de la base sans être
     * la même.
     *
     * Ce compte est peut-être celui d'un autre : l'inscription avec cette
     * adresse serait refusée, et la connexion par mot de passe ne vaut que
     * pour le titulaire du compte.
     */
    private function refusDAdresseProche(string $fournisseur): string
    {
        return 'L\'adresse transmise par '.ucfirst($fournisseur).' ne peut pas être associée automatiquement à un compte : un compte existe déjà sous une adresse que nous ne distinguons pas de la vôtre. S\'il est à vous, connectez-vous avec son adresse email et votre mot de passe ; sinon, inscrivez-vous avec une autre adresse.';
    }

    /**
     * Le refus d'une adresse hors ASCII qu'aucun compte n'occupe.
     *
     * L'inscription ne l'admet pas davantage : la base la confond avec une
     * adresse ASCII, peut-être celle d'un autre, qu'elle occuperait. Le message
     * renvoie donc au compte que la personne aurait sous une autre adresse, ou
     * à l'inscription avec une adresse en ASCII.
     */
    private function refusDAdresseHorsAscii(string $fournisseur): string
    {
        return 'L\'adresse transmise par '.ucfirst($fournisseur).' ne peut pas être associée automatiquement à un compte : la connexion avec '.ucfirst($fournisseur).' n\'accepte que les adresses en caractères ASCII. Si vous avez déjà un compte, connectez-vous avec son adresse email et votre mot de passe ; sinon, inscrivez-vous avec une adresse en caractères ASCII, sans accent.';
    }

    /**
     * La suite d'un refus quand un compte existe : son mot de passe, qu'un
     * compte ouvert par un fournisseur ne connaît pas, d'où le lien de
     * réinitialisation, qui part à l'adresse du compte.
     */
    private function versLeMotDePasse(string $quelleAdresse): string
    {
        return 'Connectez-vous avec '.$quelleAdresse.' et votre mot de passe. Si vous n\'en avez pas, « Mot de passe oublié ? » vous permet d\'en choisir un.';
    }
}
