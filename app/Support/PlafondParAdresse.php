<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Le plafond des échecs d'une adresse, toutes adresses IP confondues (#2310) :
 * les échecs qu'une adresse supporte par fenêtre, que d'autres adresses IP
 * peuvent remplir sans rien savoir du mot de passe. L'appareil qui porte le
 * cookie du compte (`AppareilConnu`) en est exempté : ses échecs ne comptent
 * pas, ses réussites remettent le compteur à zéro. Se juge une fois par
 * requête de connexion : un compte lu, pas trois.
 */
final readonly class PlafondParAdresse
{
    public const int ECHECS = 20;

    public const int SECONDES_DE_LA_FENETRE = 3600;

    private function __construct(
        private string $clef,
        private bool $exempte,
    ) {
    }

    /**
     * Le plafond de l'adresse saisie, pour l'appareil qui envoie la requête.
     */
    public static function de(Request $requete, string $adresseCanonique): self
    {
        return new self(self::clef($adresseCanonique), AppareilConnuDeLAdresse::juger($requete, $adresseCanonique));
    }

    /**
     * La clef des échecs d'une adresse, sans l'adresse IP : l'adresse sous sa
     * forme canonique, en minuscules et translittérée, comme celle du
     * limiteur par couple, pour que les saisies que la collation confond
     * partagent un seul compteur.
     */
    public static function clef(string $adresse): string
    {
        return 'echecs-de-connexion:'.Str::transliterate(Str::lower(AdresseCanonique::de($adresse)));
    }

    public static function oublier(string $adresse): void
    {
        RateLimiter::clear(self::clef($adresse));
    }

    /**
     * Refuse d'emblée une adresse dont les échecs de la dernière heure
     * passent le plafond, sauf à un appareil connu de ce compte. Même forme
     * que le limiteur du couple : une erreur sur `email`, qui renvoie vers
     * « mot de passe oublié », la sortie qui ne dépend que de la boîte. Le
     * refus ne dépend pas de l'existence du compte, l'adresse inconnue
     * comptant comme l'autre. Un essai refusé ici ne compte pas.
     *
     * @throws ValidationException
     */
    public function refuserSiAtteint(Request $requete): void
    {
        if ($this->exempte || ! RateLimiter::tooManyAttempts($this->clef, self::ECHECS)) {
            return;
        }

        event(new Lockout($requete));

        throw ValidationException::withMessages([
            'email' => trans('auth.plafond'),
        ]);
    }

    public function compterUnEchec(): void
    {
        if (! $this->exempte) {
            RateLimiter::hit($this->clef, self::SECONDES_DE_LA_FENETRE);
        }
    }

    public function remettreAZeroSiExempte(): void
    {
        if ($this->exempte) {
            RateLimiter::clear($this->clef);
        }
    }
}
