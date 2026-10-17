<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\AdresseEnAsciiImprimable;
use App\Rules\AdresseQueLaMessagerieSaitEcrire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

/**
 * L'inscription.
 *
 * Aucune règle ne regarde si l'adresse a déjà un compte (ni `unique`, ni
 * `exists`) : une erreur « adresse déjà prise » le dirait à quiconque tape
 * l'adresse d'un autre. C'est `TraiterUneInscription`, dans la file, qui
 * cherche le compte ; la page répond la même chose dans les deux cas
 * (`RegisteredUserController`). `AucuneRequeteNeDitQuUneAdresseAUnCompteTest`
 * le tient.
 */
class RegisterRequest extends FormRequest
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
     * L'adresse s'écrit en minuscules et en ASCII imprimable : la base ne
     * confond alors deux adresses que si elles sont identiques, et une
     * inscription ne peut pas occuper l'adresse d'un autre
     * (`AdresseEnAsciiImprimable`). La messagerie doit l'écrire telle quelle,
     * sans quoi le lien qui ouvre le compte ne lui parviendrait jamais
     * (`AdresseQueLaMessagerieSaitEcrire`, #2075) ; son refus est le message
     * de toute adresse mal formée. Une adresse déjà prise passe la
     * validation comme une autre.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', new AdresseEnAsciiImprimable(), new AdresseQueLaMessagerieSaitEcrire()],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ];
    }
}
