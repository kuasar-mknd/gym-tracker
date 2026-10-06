<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Une adresse email en ASCII imprimable : rien hors de U+0021 à U+007E, donc
 * ni accent, ni espace, ni lettre d'un autre alphabet.
 *
 * La colonne `users.email` et son index unique sont en `utf8mb4_unicode_ci`,
 * qui tient « jéan@example.org » pour « jean@example.org », « straße » pour
 * « strasse », une lettre pleine chasse pour sa lettre ASCII. Une adresse
 * accentuée enregistrée la première occupait donc l'adresse ASCII de
 * quelqu'un d'autre, sans que son auteur ait à en détenir la boîte : l'index
 * refusait ensuite l'inscription du titulaire, la connexion sociale aussi
 * (`ResolveSocialUserAction`), et « Mot de passe oublié ? » envoyait le lien à
 * l'adresse du compte, l'accentuée. Un compte ouvert sur l'adresse exacte, lui,
 * se reprend par la réinitialisation, qui part à cette adresse.
 *
 * Entre deux adresses en ASCII imprimable et en minuscules, la collation ne
 * confond rien : l'égalité de la base y est l'égalité octet par octet. C'est
 * le motif d'`adresseComparable()`, dans `ResolveSocialUserAction`. Un domaine
 * internationalisé s'écrit sous sa forme ASCII (« xn--… »), que les serveurs
 * de messagerie emploient de toute façon.
 *
 * L'adresse actuelle d'un compte, quand elle est donnée, reste admise telle
 * quelle : un compte ouvert avant la règle enregistre son profil sans devoir
 * changer d'adresse.
 */
final readonly class AdresseEnAsciiImprimable implements ValidationRule
{
    public const string MOTIF = '/\A[\x21-\x7E]+\z/';

    public function __construct(private ?string $adresseActuelle = null)
    {
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === $this->adresseActuelle || preg_match(self::MOTIF, $value) === 1) {
            return;
        }

        $fail('Le champ :attribute ne doit contenir que des caractères ASCII, sans accent. Un domaine internationalisé s\'écrit sous sa forme ASCII (xn--…).');
    }
}
