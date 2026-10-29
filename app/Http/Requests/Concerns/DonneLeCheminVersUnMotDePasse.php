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
 * passe actuel : leurs refus, celui du limiteur compris, donnent le chemin
 * plutôt qu'un refus sec, que seul le changement d'adresse donnait avant
 * #2104, et encore pas après cinq essais. « Mot de passe oublié ? »
 * n'est ouvert qu'aux invités, d'où la déconnexion d'abord ; le lien part à
 * l'adresse actuelle du compte.
 *
 * Le lien ne parvient pas à toute adresse qu'un compte d'avant #2075 peut
 * porter : il ne part pas à une adresse que la messagerie n'écrit pas telle
 * quelle (`User::routeNotificationForMail()`), et la demande refuse, par la
 * règle `email`, des adresses que la messagerie écrit, comme un tiret bas dans
 * le domaine. Le message ne le promet donc qu'à un compte qui le reçoit
 * (`User::peutRecevoirLeLienDeReinitialisation()`), et renvoie tout autre à
 * un administrateur, qui change l'adresse au panneau.
 *
 * Le chemin ne s'écrit qu'à la session du compte, à qui la page du profil
 * nomme déjà son fournisseur et dit si le lien lui parvient
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

        if (! $compte->peutRecevoirLeLienDeReinitialisation()) {
            return $compteRelie.'aucun lien ne peut t’en donner un : nous n’en envoyons pas à ton adresse actuelle. Demande à un administrateur de l’application de la changer.';
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
            "{$champ}.required" => self::phrase('validation.required').' '.$chemin,
            "{$champ}.current_password" => self::phrase('validation.current_password').' '.$chemin,
        ];
    }

    /**
     * Le refus du limiteur, une fois les cinq essais manqués : la phrase de
     * tout compte (`auth.throttle`), suivie du chemin pour un compte relié à
     * un fournisseur.
     *
     * Le formulaire cache son aide devant tout refus du champ : sans le
     * chemin, la personne bloquée ne le lirait plus nulle part.
     */
    protected function refusDuLimiteur(int $secondes): string
    {
        $refus = self::phrase('auth.throttle', [
            'seconds' => $secondes,
            'minutes' => ceil($secondes / 60),
        ]);
        $chemin = $this->cheminVersUnMotDePasse();

        return $chemin === null ? $refus : $refus.' '.$chemin;
    }

    /**
     * La phrase que les fichiers de `lang/fr` donnent à une clé.
     *
     * @param  array<string, float|int>  $remplacements
     */
    private static function phrase(string $cle, array $remplacements = []): string
    {
        $phrase = trans($cle, $remplacements);

        return is_string($phrase) ? $phrase : '';
    }
}
