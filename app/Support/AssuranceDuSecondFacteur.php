<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Admin;
use Illuminate\Support\Str;

/**
 * La preuve du second facteur, portée par la session du panneau (audit 5.2).
 *
 * La session garde l'empreinte du secret de l'administrateur et de l'époque de
 * ses sessions, posée quand le défi est passé ou quand le secret est enrôlé :
 * une session ouverte autrement (mot de passe seul avant l'enrôlement, rappel
 * « se souvenir de moi », session antérieure à une réinitialisation) ne la
 * porte pas, ou la porte périmée, et le panneau la ferme.
 */
final class AssuranceDuSecondFacteur
{
    public const string CLE = 'assurance_du_second_facteur';

    public static function empreinte(Admin $administrateur): string
    {
        return hash_hmac(
            'sha256',
            ($administrateur->getAppAuthenticationSecret() ?? '').'|'.self::epoque($administrateur),
            is_string($cle = config('app.key')) ? $cle : '',
        );
    }

    public static function marquer(Admin $administrateur): void
    {
        session()->put(self::CLE, self::empreinte($administrateur));
    }

    public static function estPortee(Admin $administrateur): bool
    {
        $portee = session()->get(self::CLE);

        return is_string($portee) && hash_equals(self::empreinte($administrateur), $portee);
    }

    /**
     * Périme la preuve de toutes les sessions ouvertes de l'administrateur.
     */
    public static function fermerLesSessions(Admin $administrateur): void
    {
        MagasinDurable::depot()->forever(self::cleDeLEpoque($administrateur), Str::random(32));
    }

    private static function epoque(Admin $administrateur): string
    {
        $epoque = MagasinDurable::depot()->get(self::cleDeLEpoque($administrateur));

        return is_string($epoque) ? $epoque : '';
    }

    private static function cleDeLEpoque(Admin $administrateur): string
    {
        return 'panneau:epoque-du-second-facteur:'.$administrateur->id;
    }
}
