<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\DonneLeCheminVersUnMotDePasse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Changer le mot de passe exige le mot de passe actuel, compté sous la clé
 * qu'il partage avec le changement d'adresse (`throttleKey()`). Un compte
 * relié à un fournisseur de connexion peut n'en avoir jamais choisi : le refus
 * lui donne le chemin vers un mot de passe (`DonneLeCheminVersUnMotDePasse`,
 * #2104).
 */
class UpdatePasswordRequest extends FormRequest
{
    use DonneLeCheminVersUnMotDePasse;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `bail` et `string` avant `current_password`, qui passe la valeur telle
     * quelle à `password_verify()` : un tableau y levait une erreur 500 au
     * lieu d'un refus (#2071).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['bail', 'required', 'string', 'current_password', 'max:255'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ];
    }

    /**
     * Les refus de tout compte, suivis du chemin vers un mot de passe pour un
     * compte relié à un fournisseur.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return $this->refusDuMotDePasseActuel('current_password');
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            if ($validator->errors()->has('current_password')) {
                $this->hitRateLimiter();
            }
        });
    }

    public function throttleKey(): string
    {
        return 'update-password-'.$this->user()?->id;
    }

    public function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey());
    }

    public function viderLeCompteurDeTentatives(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Coupe la requête avant validation quand le compteur d'essais est plein.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());

            throw ValidationException::withMessages([
                'current_password' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }
    }

    #[\Override]
    protected function passedValidation(): void
    {
        $this->viderLeCompteurDeTentatives();
    }
}
