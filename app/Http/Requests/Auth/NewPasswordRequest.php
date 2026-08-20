<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'password_confirmation' => 'required',
        ];
    }
}
