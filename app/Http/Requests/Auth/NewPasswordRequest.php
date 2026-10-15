<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\LiensDesCourriels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

/**
 * La reinitialisation de mot de passe, validee hors du controleur.
 *
 * Les regles etaient ecrites en ligne dans `NewPasswordController::store()`
 * via `Request::validate()`. C'est une macro : l'analyse statique lui donne
 * `mixed` en retour, donc rien ne verifiait plus ce qui entrait dans
 * `resetPassword()` — l'erreur etait masquee dans le baseline (#1482).
 *
 * Une `FormRequest` rend un `array<string, mixed>` connu, et rejoint la
 * convention des trois classes voisines de ce dossier.
 *
 * Le formulaire n'envoie plus l'adresse du compte en clair : il renvoie
 * `adresse`, la valeur chiffrée que porte le lien du courriel
 * (`LiensDesCourriels::lienDeReinitialisation()`), et seul
 * `identifiantsDuLien()` la déchiffre. Aucune règle n'en juge : absente ou
 * illisible, elle refuse le lien comme un jeton faux, sous le même plancher
 * de durée, là où une erreur de validation répondrait autrement et aussitôt.
 */
class NewPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Le jeton est une chaîne : un tableau faisait lever la vérification de
     * son empreinte, et le refus devenait une erreur 500 pour un compte qui
     * venait de demander un lien, une redirection pour tout autre.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'adresse' => ['nullable'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'password_confirmation' => 'required',
        ];
    }

    /**
     * Ce que le courtier reçoit : le jeton, l'adresse du lien déchiffrée sous
     * la clef `email` où il la cherche, et le nouveau mot de passe ; null quand
     * le lien ne porte pas d'adresse qui se déchiffre.
     *
     * L'adresse en clair n'est fusionnée dans aucune requête, ni dans celle de
     * l'application (`request()`), ni dans celle-ci : la page envoie du JSON,
     * et `Request::createFrom()` donne à cette requête le même corps JSON que
     * celle de l'application, si bien qu'une valeur fusionnée ici s'y lit
     * aussi. Elle partirait alors dans la saisie que la session garde après
     * un refus et dans le corps qu'enregistre une exception. Seul un corps
     * encodé comme un formulaire HTML est copié d'une requête à l'autre.
     *
     * @return array{token: string, email: string, password: string, password_confirmation: string}|null
     */
    public function identifiantsDuLien(): ?array
    {
        $adresse = LiensDesCourriels::adresseDuLienDeReinitialisation($this->validated('adresse'));

        if ($adresse === null) {
            return null;
        }

        return [
            'token' => $this->string('token')->value(),
            'email' => $adresse,
            'password' => $this->string('password')->value(),
            'password_confirmation' => $this->string('password_confirmation')->value(),
        ];
    }
}
