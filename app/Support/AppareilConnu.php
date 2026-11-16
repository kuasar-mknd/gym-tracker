<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\AuthentifieLaSessionDuCompte;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use LogicException;

/**
 * Le cookie d'appareil connu (#2310) : il dit que cet appareil a déjà ouvert
 * ce compte, et lui épargne le plafond d'échecs par adresse
 * (`PlafondParAdresse::ECHECS`), que d'autres adresses IP peuvent
 * remplir sans rien savoir du mot de passe.
 *
 * Il porte l'identifiant du compte et une empreinte de son mot de passe, comme
 * le cookie « se souvenir de moi » (`RappelDeLAppareil`), et jamais son jeton
 * de rappel : la déconnexion le fait tourner, et le cookie mourrait avec elle.
 * Seul un changement de mot de passe le révoque. L'empreinte est un HMAC à clef
 * secrète (`hashPasswordForCookie()`) : le cookie se lit sans rien livrer et ne
 * se forge pas. Exclu d'`EncryptCookies` (bootstrap/app.php) : chiffré, il
 * pesait 500 octets et faisait passer la réponse de connexion sous le tampon de
 * 4 Kio du proxy (`EnTetesDeReponseTest`, `.ai/rules/middleware.md`). Falsifié,
 * illisible ou d'un autre compte, il vaut un cookie absent.
 */
final class AppareilConnu
{
    public const string NOM = 'appareil_connu';

    public const int MINUTES_DE_VIE = 180 * 24 * 60;

    private const int LONGUEUR_DE_L_EMPREINTE = 32;

    /**
     * L'appareil porte-t-il un cookie valable pour CE compte ?
     */
    public static function vautPour(Request $request, User $compte): bool
    {
        [$identifiant, $empreinte] = self::lire($request);

        return $identifiant === (string) $compte->id
            && hash_equals(self::empreinteDe($compte), $empreinte);
    }

    /**
     * Le compte que désigne le cookie, s'il existe : la seule lecture avant
     * la garde, par la clef primaire.
     */
    public static function compteDe(Request $request): ?User
    {
        $identifiant = self::lire($request)[0];

        return $identifiant === '' ? null : User::query()->find((int) $identifiant);
    }

    /**
     * Met en file le cookie de ce compte, pour 180 jours à compter de la
     * réponse.
     */
    public static function poser(User $compte): void
    {
        Cookie::queue(Cookie::make(
            self::NOM,
            $compte->id.'|'.self::empreinteDe($compte),
            self::MINUTES_DE_VIE,
            '/',
            null,
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        ));
    }

    /**
     * L'identifiant et l'empreinte du cookie, vides quand il manque. Sans
     * barre verticale, l'empreinte est vide et l'identifiant est tout le
     * cookie : il ne vaut rien, `vautPour()` compare l'empreinte.
     *
     * @return array{string, string}
     */
    private static function lire(Request $request): array
    {
        [$identifiant, $empreinte] = array_pad(explode('|', (string) $request->cookies->get(self::NOM), 2), 2, '');

        return [$identifiant, $empreinte];
    }

    private static function empreinteDe(User $compte): string
    {
        $garde = Auth::guard(AuthentifieLaSessionDuCompte::GARDE);

        if (! $garde instanceof SessionGuard) {
            throw new LogicException('La garde des comptes tient sa connexion en session.');
        }

        return substr($garde->hashPasswordForCookie($compte->getAuthPassword()), 0, self::LONGUEUR_DE_L_EMPREINTE);
    }
}
