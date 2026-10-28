<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\DonneLeCheminVersUnMotDePasse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Supprimer le compte exige le mot de passe actuel, compté par le limiteur
 * propre à la suppression (`throttleKey()`). Un compte relié à un fournisseur
 * de connexion peut n'en avoir jamais choisi : le refus lui donne le chemin
 * vers un mot de passe (`DonneLeCheminVersUnMotDePasse`, #2104).
 */
class DeleteUserRequest extends FormRequest
{
    use DonneLeCheminVersUnMotDePasse;

    /**
     * La requête ne vérifie que la connexion ; l'autorisation vit dans le
     * contrôleur, et son refus est rendu en 404 par bootstrap/app.php.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `bail` et `string` avant `current_password`, qui passe la valeur telle
     * quelle à `password_verify()` : un tableau y levait une erreur 500 au
     * lieu d'un refus (#2071).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['bail', 'required', 'string', 'current_password', 'max:255'],
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
        return $this->refusDuMotDePasseActuel('password');
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            if ($validator->errors()->has('password')) {
                $this->hitRateLimiter();
            }
        });
    }

    public function throttleKey(): string
    {
        return 'delete-account-'.$this->user()?->id;
    }

    public function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey());
    }

    public function viderLeCompteurDeTentatives(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    #[\Override]
    protected function prepareForValidation(): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());

            throw ValidationException::withMessages([
                'password' => trans('auth.throttle', [
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
