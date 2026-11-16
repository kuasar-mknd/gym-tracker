<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Actions\Auth\ConnecterLeCompte;
use App\Support\AdresseCanonique;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Les échecs qu'une adresse supporte, toutes adresses IP confondues, par
     * fenêtre (#2310).
     */
    public const int ECHECS_PAR_ADRESSE = 20;

    public const int SECONDES_DE_LA_FENETRE_PAR_ADRESSE = 3600;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Connecte le compte par `ConnecterLeCompte`, qui retient un compte en
     * attente dont le lien d'ouverture n'a pas été suivi. Tout refus a le même
     * message, `auth.failed`, et compte un essai sous la clef de l'adresse et
     * de l'adresse IP.
     *
     * La garde cherche le compte sous la forme canonique de l'adresse saisie
     * (`AdresseCanonique`, #2168) : un domaine internationalisé tapé en
     * Unicode trouve le compte que l'inscription a enregistré en ASCII, la
     * forme que Chromium envoie. La base cherche ensuite comme avant, selon sa
     * collation. La saisie elle-même ne change pas : la session la garde telle
     * quelle pour la page suivante, et un refus répond comme avant.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $identifiants = [
            'email' => $this->adresseCanonique(),
            'password' => $this->string('password')->value(),
        ];

        if (! app(ConnecterLeCompte::class)->execute($identifiants, $this->boolean('remember'), $this->session())) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit(self::clefDeLAdresse($this->adresseCanonique()), self::SECONDES_DE_LA_FENETRE_PAR_ADRESSE);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $this->ensureAddressIsNotOverLimit();

        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Refuse d'emblée, en 429, une adresse dont les échecs de la dernière
     * heure, de toutes les adresses IP, passent le plafond (#2310). Le refus
     * ne tient aucun processus : il répond avant le plancher des réponses, et
     * ne dépend pas de l'existence du compte, l'adresse inconnue comptant comme
     * l'autre. Un essai refusé ici ne compte pas : le plafond se lève à la fin
     * de la fenêtre, ou par la réinitialisation du mot de passe.
     *
     * @throws \Illuminate\Http\Exceptions\ThrottleRequestsException
     */
    private function ensureAddressIsNotOverLimit(): void
    {
        $clef = self::clefDeLAdresse($this->adresseCanonique());

        if (! RateLimiter::tooManyAttempts($clef, self::ECHECS_PAR_ADRESSE)) {
            return;
        }

        event(new Lockout($this));

        throw new ThrottleRequestsException(
            'Too Many Attempts.',
            headers: ['Retry-After' => RateLimiter::availableIn($clef)],
        );
    }

    /**
     * La clef des échecs d'une adresse, sans l'adresse IP : l'adresse sous sa
     * forme canonique, en minuscules et translittérée, comme celle du
     * limiteur par couple, pour que les saisies que la collation confond
     * partagent un seul compteur.
     */
    public static function clefDeLAdresse(string $adresse): string
    {
        return 'echecs-de-connexion:'.Str::transliterate(Str::lower(AdresseCanonique::de($adresse)));
    }

    /**
     * La clef du limiteur : l'adresse sous sa forme canonique, puis l'adresse
     * IP. Sur la saisie brute, les deux formes d'un domaine internationalisé
     * auraient chacune leurs cinq essais, et la translittération faisait de
     * « bücher » la clef de « bucher », un autre domaine (#2168).
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->adresseCanonique()).'|'.$this->ip());
    }

    /**
     * L'adresse saisie, son domaine en ASCII quand il est internationalisé.
     */
    private function adresseCanonique(): string
    {
        return AdresseCanonique::de($this->string('email')->value());
    }
}
