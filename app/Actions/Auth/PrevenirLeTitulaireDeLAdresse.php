<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\MotifDeLaTentative;
use App\Models\User;
use App\Notifications\TentativeSurTonAdresse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Prévient le titulaire d'une adresse déjà prise qu'on a voulu s'en servir
 * (`TentativeSurTonAdresse`), au plus une fois par heure et par compte.
 *
 * L'inscription et le changement d'adresse du profil partagent la borne :
 * sans elle, n'importe qui pourrait faire écrire l'application à une adresse
 * autant de fois qu'il le demande. Les demandes au-delà n'envoient rien, sans
 * que la réponse de la page change. La clef nomme le compte, jamais
 * l'adresse.
 */
final readonly class PrevenirLeTitulaireDeLAdresse
{
    /**
     * Le temps, en secondes, entre deux avis au même compte.
     */
    public const int SECONDES_ENTRE_DEUX_AVIS = 3600;

    /**
     * Rend vrai quand l'avis part, faux quand la borne le retient.
     */
    public function execute(User $titulaire, MotifDeLaTentative $motif): bool
    {
        return RateLimiter::attempt(
            'avis-sur-l-adresse:'.$titulaire->id,
            1,
            static function () use ($titulaire, $motif): void {
                $titulaire->notify(new TentativeSurTonAdresse($motif));
            },
            self::SECONDES_ENTRE_DEUX_AVIS,
        ) !== false;
    }
}
