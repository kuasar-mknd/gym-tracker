<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\User;

/**
 * Le chemin vers un mot de passe, que les refus du mot de passe actuel donnent
 * à un compte relié à un fournisseur de connexion.
 *
 * La connexion sociale crée le compte avec un mot de passe tiré au hasard
 * (`ResolveSocialUserAction`), que personne ne connaît. Changer l'adresse
 * (`ProfileUpdateRequest`), changer le mot de passe (`UpdatePasswordRequest`)
 * et supprimer le compte (`DeleteUserRequest`) exigent pourtant le mot de
 * passe actuel : leur refus donne le chemin plutôt qu'un refus sec, que seul
 * le changement d'adresse donnait avant #2104. « Mot de passe oublié ? »
 * n'est ouvert qu'aux invités, d'où la déconnexion d'abord ; le lien part à
 * l'adresse actuelle du compte.
 *
 * Le lien ne part pas à une adresse que la messagerie n'écrit pas telle
 * quelle, qu'un compte d'avant #2075 peut porter
 * (`User::routeNotificationForMail()`), et la demande refuse même des points
 * consécutifs ou un point final (règle `email`) : le message ne le promet pas,
 * et renvoie à un administrateur, qui change l'adresse au panneau.
 *
 * Le chemin ne s'écrit qu'à la session du compte, à qui la page du profil
 * nomme déjà son fournisseur et dit si la messagerie écrit son adresse
 * (`ProfileController::edit`) : il n'en apprend rien de plus. Le formulaire
 * en montre le même texte avant l'envoi
 * (`resources/js/Components/Form/CheminVersUnMotDePasse.vue`). Un compte sans
 * fournisseur garde ses refus tels quels.
 *
 * @phpstan-require-extends \Illuminate\Foundation\Http\FormRequest
 */
trait DonneLeCheminVersUnMotDePasse
{
    /**
     * Le chemin, ou null pour un compte sans fournisseur.
     */
    protected function cheminVersUnMotDePasse(): ?string
    {
        $compte = $this->user();

        if (! $compte instanceof User) {
            return null;
        }

        $fournisseur = $compte->fournisseurDeConnexion();

        if ($fournisseur === null) {
            return null;
        }

        $compteRelie = "Ton compte est relié à {$fournisseur}. Si tu n’as jamais choisi de mot de passe, ";

        if (! $compte->laMessagerieSaitEcrireSonAdresse()) {
            return $compteRelie.'aucun lien ne peut t’en donner un : notre messagerie ne sait pas écrire à ton adresse actuelle. Demande à un administrateur de l’application de la changer.';
        }

        return $compteRelie.'choisis-en un d’abord : déconnecte-toi, puis « Mot de passe oublié ? » sur la page de connexion. Le lien part à ton adresse actuelle.';
    }

    /**
     * Les refus du champ qui porte le mot de passe actuel, suivis du chemin
     * pour un compte relié à un fournisseur ; aucun pour un autre compte, qui
     * garde ceux de `lang/fr/validation.php`.
     *
     * Le refus garde sa phrase, celle de tout compte, et le validateur y
     * remplace `:attribute` par le nom français du champ. Le champ vide en
     * fait partie : un compte qui n'a jamais choisi de mot de passe n'a rien à
     * y taper, et le formulaire cache son aide devant le refus du serveur.
     *
     * @return array<string, string>
     */
    protected function refusDuMotDePasseActuel(string $champ): array
    {
        $chemin = $this->cheminVersUnMotDePasse();

        if ($chemin === null) {
            return [];
        }

        return [
            "{$champ}.required" => self::phraseDuValidateur('required').' '.$chemin,
            "{$champ}.current_password" => self::phraseDuValidateur('current_password').' '.$chemin,
        ];
    }

    /**
     * La phrase que `lang/fr/validation.php` donne à une règle.
     */
    private static function phraseDuValidateur(string $regle): string
    {
        $phrase = trans("validation.{$regle}");

        return is_string($phrase) ? $phrase : '';
    }
}
