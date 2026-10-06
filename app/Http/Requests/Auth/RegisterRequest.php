<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Rules\AdresseEnAsciiImprimable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

/**
 * L'inscription.
 *
 * Meme raison que `NewPasswordRequest` (#1482). `CreateUserAction::execute()`
 * attend un `array<string, string>` : c'est le controleur qui construit ce
 * tableau, champ par champ, plutot que d'elargir la signature de l'action
 * pour la faire taire. L'action veut trois chaines, on lui donne trois
 * chaines.
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
     * (`AdresseEnAsciiImprimable`).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', new AdresseEnAsciiImprimable(), 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ];
    }
}
