<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SocialAuthException;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;

/**
 * Retrouve, rattache ou crée le compte d'un retour de connexion sociale.
 *
 * L'ordre compte :
 *
 *  1. l'identité du fournisseur (`provider`, `provider_id`) désigne le compte
 *     qu'elle a déjà ouvert, même si l'adresse rendue a changé depuis, sauf
 *     quand cette adresse n'est que proche de celle du compte ;
 *  2. à défaut, l'adresse ne rattache un compte existant que si elle est
 *     vérifiée par le fournisseur et identique à celle du compte, à la casse
 *     ASCII près ;
 *  3. sinon, un compte est créé, à condition qu'aucun compte n'occupe déjà une
 *     adresse que la base tiendrait pour la même.
 *
 * Toute comparaison d'adresse et d'identifiant se fait en PHP, octet par
 * octet. La base ne sert qu'à trouver les candidats : sa collation
 * (`utf8mb4_unicode_ci`) ignore accents et casse, replie « ß » sur « ss », le
 * signe kelvin sur « k », les formes pleine chasse sur l'ASCII, et néglige les
 * espaces finales. Une adresse seulement proche de celle d'un compte n'ouvre
 * donc jamais ce compte, même vérifiée chez le fournisseur.
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

        $compteDeLIdentite = $this->compteDeLIdentite($fournisseur, $identifiant, $utilisateurSocial);

        if ($compteDeLIdentite !== null) {
            return $compteDeLIdentite;
        }

        $adresse = $utilisateurSocial->getEmail();

        if (! is_string($adresse) || $adresse === '') {
            throw new SocialAuthException(ucfirst($fournisseur).' ne nous a transmis aucune adresse email. '.$this->suiteARefus());
        }

        $adresseComparable = $this->adresseComparable($adresse);

        if ($adresseComparable === null) {
            throw new SocialAuthException($this->refusDAdresse($fournisseur));
        }

        $compteDeLAdresse = User::query()->where('email', $adresse)->first();

        if ($compteDeLAdresse === null) {
            return $this->creerLeCompte($fournisseur, $identifiant, $utilisateurSocial, $adresse, $adresseVerifiee);
        }

        return $this->rattacher($compteDeLAdresse, $fournisseur, $identifiant, $utilisateurSocial, $adresseComparable, $adresseVerifiee);
    }

    /**
     * Le compte qu'ouvre déjà cette identité du fournisseur.
     *
     * La base rend les candidats selon sa collation, qui ne distingue pas
     * « AbC » de « abc » : le filtre exact se fait ici. Plusieurs comptes
     * peuvent porter la même identité, l'ancienne recherche par adresse en
     * créant un second quand l'adresse changeait chez le fournisseur. Le retour
     * va alors à celui dont l'adresse est exactement celle rendue, et il est
     * refusé s'il n'y en a aucun : choisir au hasard ouvrirait peut-être le
     * compte d'un autre.
     *
     * Un seul compte n'est pas pour autant ouvert d'office : voir
     * `lieeSurUneAdresseSeulementProche()`.
     *
     * @throws SocialAuthException
     */
    private function compteDeLIdentite(string $fournisseur, string $identifiant, SocialUser $utilisateurSocial): ?User
    {
        $comptes = User::query()
            ->where('provider', $fournisseur)
            ->where('provider_id', $identifiant)
            ->orderBy('id')
            ->get()
            ->filter(static fn (User $compte): bool => $compte->provider === $fournisseur && $compte->provider_id === $identifiant)
            ->values();

        if ($comptes->count() <= 1) {
            $compte = $comptes->first();

            if ($compte !== null && $this->lieeSurUneAdresseSeulementProche($compte, $utilisateurSocial->getEmail())) {
                Log::warning('Connexion sociale refusée : identité liée à un compte d’adresse seulement proche', [
                    'fournisseur' => $fournisseur,
                    'compte' => $compte->getKey(),
                ]);

                throw new SocialAuthException($this->refusDAdresse($fournisseur));
            }

            return $compte;
        }

        $adresseRendue = $this->adresseComparable($utilisateurSocial->getEmail());
        $compteDeLAdresseExacte = $adresseRendue === null
            ? null
            : $comptes->first(fn (User $compte): bool => $this->adresseComparable($compte->email) === $adresseRendue);

        if ($compteDeLAdresseExacte !== null) {
            return $compteDeLAdresseExacte;
        }

        Log::warning('Connexion sociale refusée : plusieurs comptes portent cette identité', [
            'fournisseur' => $fournisseur,
            'comptes' => $comptes->modelKeys(),
        ]);

        throw new SocialAuthException('Plusieurs comptes sont associés à ce compte '.ucfirst($fournisseur).'. '.$this->suiteARefus());
    }

    /**
     * L'identité a-t-elle été liée à ce compte sur une adresse seulement proche ?
     *
     * Avant la comparaison exacte, la recherche par adresse liait l'identité au
     * compte que la collation tenait pour le même, même quand son adresse ne
     * l'était pas. Une telle liaison se reconnaît tant que le fournisseur rend
     * la même adresse : la base la confond avec celle du compte, mais elle n'en
     * est ni la copie exacte ni la variante en casse ASCII. Le retour est alors
     * refusé, comme il l'est aujourd'hui sur la recherche par adresse.
     *
     * Une adresse que la base ne confond pas avec celle du compte est un vrai
     * changement d'adresse chez le fournisseur : l'identité, qui ne change pas,
     * continue d'ouvrir son compte. La comparaison passe par un paramètre lié.
     */
    private function lieeSurUneAdresseSeulementProche(User $compte, ?string $adresse): bool
    {
        if ($adresse === null || $adresse === $compte->email) {
            return false;
        }

        $adresseComparable = $this->adresseComparable($adresse);

        if ($adresseComparable !== null && $adresseComparable === $this->adresseComparable($compte->email)) {
            return false;
        }

        return User::query()->whereKey($compte->getKey())->where('email', $adresse)->exists();
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
        string $adresseComparable,
        bool $adresseVerifiee,
    ): User {
        if ($this->adresseComparable($compte->email) !== $adresseComparable) {
            Log::warning('Connexion sociale refusée : adresse seulement proche de celle d’un compte', [
                'fournisseur' => $fournisseur,
                'compte' => $compte->getKey(),
            ]);

            throw new SocialAuthException($this->refusDAdresse($fournisseur));
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

            throw new SocialAuthException('Ce compte est déjà associé à un autre compte '.ucfirst($fournisseur).'. '.$this->suiteARefus());
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
     * La forme sous laquelle deux adresses se comparent, ou null si elle n'en a pas.
     *
     * Seule une adresse en ASCII imprimable, sans espace, en a une : ses
     * lettres en minuscules, par `strtolower()`, qui ne touche que les
     * vingt-six lettres ASCII depuis PHP 8.2. C'est le seul repli que l'index
     * unique de la base fait aussi sur l'ASCII, et le seul qu'on lui emprunte.
     *
     * Une adresse non ASCII est refusée plutôt que normalisée. NFC ne
     * réconcilie ni les formes pleine chasse ni le signe kelvin, que la base
     * confond pourtant avec l'ASCII ; `mb_strtolower()` et le repli de casse
     * Unicode créent eux-mêmes des égalités (le signe kelvin, U+212A, y
     * devient « k ») ; et un domaine internationalisé s'écrit de deux façons
     * (Unicode ou Punycode) que rien ici ne saurait apparier. Les trois
     * fournisseurs rendent de l'ASCII pour l'immense majorité des comptes ;
     * les autres s'inscrivent avec leur adresse et un mot de passe.
     */
    private function adresseComparable(mixed $adresse): ?string
    {
        if (! is_string($adresse) || preg_match('/\A[\x21-\x7E]+\z/', $adresse) !== 1) {
            return null;
        }

        return strtolower($adresse);
    }

    private function refusDAdresse(string $fournisseur): string
    {
        return 'L\'adresse transmise par '.ucfirst($fournisseur).' ne peut pas être associée automatiquement à un compte. '.$this->suiteARefus();
    }

    private function suiteARefus(): string
    {
        return 'Connectez-vous avec votre email et votre mot de passe, ou inscrivez-vous.';
    }
}
